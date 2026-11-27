import ctypes
import json
import os
import resource
import select
import signal
import sys
import time

PR_SET_CHILD_SUBREAPER = 36
PR_SET_NO_NEW_PRIVS = 38
ORPHAN_REAPING_MILLISECONDS = 50
CLOCK_TICKS_PER_SECOND = os.sysconf('SC_CLK_TCK')

LANDLOCK_CREATE_RULESET = 444
LANDLOCK_ADD_RULE = 445
LANDLOCK_RESTRICT_SELF = 446
LANDLOCK_CREATE_RULESET_VERSION = 1
LANDLOCK_RULE_PATH_BENEATH = 1
LANDLOCK_MINIMUM_ABI = 3

ACCESS_EXECUTE = 1 << 0
ACCESS_WRITE_FILE = 1 << 1
ACCESS_READ_FILE = 1 << 2
ACCESS_READ_DIR = 1 << 3
ACCESS_REFER = 1 << 13
ACCESS_TRUNCATE = 1 << 14
ACCESS_IOCTL_DEV = 1 << 15
ACCESS_WITHOUT_ABI_REQUIREMENT = (1 << 13) - 1
FILE_ACCESS = ACCESS_EXECUTE | ACCESS_WRITE_FILE | ACCESS_READ_FILE | ACCESS_TRUNCATE | ACCESS_IOCTL_DEV
READ_ACCESS = ACCESS_EXECUTE | ACCESS_READ_FILE | ACCESS_READ_DIR

SCOPE_ABSTRACT_UNIX_SOCKET = 1 << 0
SCOPE_SIGNAL = 1 << 1
SCOPED_ABI = 6
NETWORK_ABI = 4


class RulesetAttributes(ctypes.Structure):
    _fields_ = [('handled_access_fs', ctypes.c_uint64), ('handled_access_net', ctypes.c_uint64), ('scoped', ctypes.c_uint64)]


class PathBeneathAttributes(ctypes.Structure):
    _pack_ = 1
    _fields_ = [('allowed_access', ctypes.c_uint64), ('parent_fd', ctypes.c_int32)]


def landlock_abi(library):
    abi = library.syscall(LANDLOCK_CREATE_RULESET, None, ctypes.c_size_t(0), ctypes.c_uint32(LANDLOCK_CREATE_RULESET_VERSION))
    if abi < 0:
        raise OSError(ctypes.get_errno(), f'Landlock is not available: {os.strerror(ctypes.get_errno())}')
    return abi


def handled_access(abi):
    access = ACCESS_WITHOUT_ABI_REQUIREMENT
    if abi >= 2:
        access |= ACCESS_REFER
    if abi >= 3:
        access |= ACCESS_TRUNCATE
    if abi >= 5:
        access |= ACCESS_IOCTL_DEV
    return access


def confine(library, readable_paths, writable_paths):
    abi = landlock_abi(library)
    if abi < LANDLOCK_MINIMUM_ABI:
        raise OSError(0, f'Landlock ABI {abi} does not control truncation; at least {LANDLOCK_MINIMUM_ABI} is needed')
    handled = handled_access(abi)
    attributes = RulesetAttributes(handled, 0, SCOPE_ABSTRACT_UNIX_SOCKET | SCOPE_SIGNAL if abi >= SCOPED_ABI else 0)
    attributes_size = 24 if abi >= SCOPED_ABI else 16 if abi >= NETWORK_ABI else 8
    ruleset = library.syscall(LANDLOCK_CREATE_RULESET, ctypes.byref(attributes), ctypes.c_size_t(attributes_size), ctypes.c_uint32(0))
    if ruleset < 0:
        raise OSError(ctypes.get_errno(), f'Unable to create the Landlock ruleset: {os.strerror(ctypes.get_errno())}')
    try:
        for paths, access in ((readable_paths, READ_ACCESS & handled), (writable_paths, handled)):
            for path in paths:
                allow(library, ruleset, path, access)
        if library.prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0:
            raise OSError(ctypes.get_errno(), f'Unable to forbid new privileges: {os.strerror(ctypes.get_errno())}')
        if library.syscall(LANDLOCK_RESTRICT_SELF, ruleset, ctypes.c_uint32(0)) != 0:
            raise OSError(ctypes.get_errno(), f'Unable to apply the Landlock ruleset: {os.strerror(ctypes.get_errno())}')
    finally:
        os.close(ruleset)


def allow(library, ruleset, path, access):
    try:
        descriptor = os.open(path, os.O_PATH | os.O_CLOEXEC)
    except OSError as error:
        raise OSError(error.errno, f'Unable to open {path}: {error.strerror}') from error
    try:
        if not os.path.isdir(f'/proc/self/fd/{descriptor}'):
            access &= FILE_ACCESS
        rule = PathBeneathAttributes(access, descriptor)
        if library.syscall(LANDLOCK_ADD_RULE, ruleset, ctypes.c_int(LANDLOCK_RULE_PATH_BENEATH), ctypes.byref(rule), ctypes.c_uint32(0)) != 0:
            raise OSError(ctypes.get_errno(), f'Unable to allow {path}: {os.strerror(ctypes.get_errno())}')
    finally:
        os.close(descriptor)


def attempt_processes(own_id, group_id):
    statistics = {}
    for entry in os.listdir('/proc'):
        if not entry.isdigit() or int(entry) == own_id:
            continue
        try:
            with open(f'/proc/{entry}/stat', encoding='ascii', errors='replace') as stat_file:
                stat = stat_file.read()
        except OSError:
            continue
        statistics[int(entry)] = stat[stat.rindex(')') + 2:].split()
    children = {}
    for process_id, fields in statistics.items():
        children.setdefault(int(fields[1]), []).append(process_id)
    members = {process_id for process_id, fields in statistics.items() if int(fields[2]) == group_id}
    pending = [own_id, *members]
    while pending:
        for child_id in children.get(pending.pop(), []):
            if child_id not in members:
                members.add(child_id)
                pending.append(child_id)
    return {process_id: statistics[process_id] for process_id in members}


def attempt_usage(own_id, group_id):
    processes = attempt_processes(own_id, group_id)
    ticks = sum(int(fields[11]) + int(fields[12]) + int(fields[13]) + int(fields[14]) for fields in processes.values())
    tasks = sum(max(1, int(fields[17])) for fields in processes.values())
    reaped = resource.getrusage(resource.RUSAGE_CHILDREN)
    return reaped.ru_utime + reaped.ru_stime + ticks / CLOCK_TICKS_PER_SECOND, tasks


def exceeded_limit(limits):
    cpu_time_seconds, process_count = limits
    if cpu_time_seconds is None and process_count is None:
        return None
    own_id = os.getpid()
    group_id = os.getpgrp()
    used_seconds, tasks = attempt_usage(own_id, group_id)
    if process_count is not None and tasks > process_count:
        return 'processes'
    if cpu_time_seconds is not None and used_seconds > cpu_time_seconds and attempt_usage(own_id, group_id)[0] > cpu_time_seconds:
        return 'cpu_time'
    return None


def stop_and_reap_remaining():
    own_id = os.getpid()
    group_id = os.getpgrp()
    while True:
        for process_id in attempt_processes(own_id, group_id):
            try:
                os.kill(process_id, signal.SIGKILL)
            except ProcessLookupError:
                pass
        try:
            reaped_id, _, _ = os.wait4(-1, os.WNOHANG)
        except ChildProcessError:
            return
        if reaped_id == 0:
            time.sleep(0.005)


def open_measurement(path):
    try:
        return os.open(path, os.O_WRONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    except OSError as error:
        sys.stderr.write(f'Unable to open the measurement file {path}: {error.strerror}\n')
        return None


def write_measurement(measurement_descriptor, measurement):
    if measurement_descriptor is None:
        return
    contents = json.dumps(measurement).encode('utf-8')
    try:
        os.ftruncate(measurement_descriptor, 0)
        written = 0
        while written < len(contents):
            written += os.pwrite(measurement_descriptor, contents[written:], written)
    except OSError as error:
        sys.stderr.write(f'Unable to write the measurement: {error.strerror}\n')
    finally:
        os.close(measurement_descriptor)


def read_confinement_error(error_descriptor):
    message = b''
    while True:
        chunk = os.read(error_descriptor, 4096)
        if not chunk:
            break
        message += chunk
    os.close(error_descriptor)
    return message.decode('utf-8', errors='replace') or None


def wait_for_child(child_id, timeout_seconds, started_at, limits):
    child_descriptor = os.pidfd_open(child_id)
    poller = select.poll()
    poller.register(child_descriptor, select.POLLIN)
    deadline = started_at + timeout_seconds
    try:
        while True:
            limit = exceeded_limit(limits)
            remaining_seconds = deadline - time.monotonic()
            if limit is not None:
                return None, 'timed_out' if remaining_seconds <= 0 else limit
            reaped_id, status, _ = os.wait4(-1, os.WNOHANG)
            while reaped_id != 0:
                if reaped_id == child_id:
                    return status, None
                reaped_id, status, _ = os.wait4(-1, os.WNOHANG)
            if remaining_seconds <= 0:
                return None, 'timed_out'
            poller.poll(max(1, min(ORPHAN_REAPING_MILLISECONDS, int(remaining_seconds * 1000))))
    finally:
        os.close(child_descriptor)


def parse_arguments(arguments):
    if len(arguments) < 2:
        return None
    readable_paths = []
    writable_paths = []
    cpu_time_seconds = None
    process_count = None
    position = 2
    while position + 1 < len(arguments) and arguments[position] in ('--read', '--write', '--cpu-time', '--processes'):
        option, value = arguments[position], arguments[position + 1]
        if option == '--cpu-time':
            cpu_time_seconds = float(value)
        elif option == '--processes':
            process_count = int(value)
        else:
            (readable_paths if option == '--read' else writable_paths).append(value)
        position += 2
    if position + 1 >= len(arguments) or arguments[position] != '--':
        return None
    return float(arguments[0]), arguments[1], (cpu_time_seconds, process_count), readable_paths, writable_paths, arguments[position + 1:]


def main():
    library = ctypes.CDLL(None, use_errno=True)
    if sys.argv[1:] == ['--landlock-abi']:
        try:
            print(landlock_abi(library))
        except OSError as error:
            sys.stderr.write(f'{error.strerror}\n')
            return 1
        return 0

    arguments = parse_arguments(sys.argv[1:])
    if arguments is None:
        sys.stderr.write('Usage: measure-process-tree.py <timeout-seconds> <measurement-path> [--cpu-time <seconds>] [--processes <count>] [--read <path>]... [--write <path>]... -- <command> [argument...]\n')
        sys.stderr.write('       measure-process-tree.py --landlock-abi\n')
        return 2
    timeout_seconds, measurement_path, limits, readable_paths, writable_paths, command = arguments
    measurement_descriptor = open_measurement(measurement_path)

    if library.prctl(PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0) != 0:
        sys.stderr.write(f'Unable to become the subreaper of the attempt: {os.strerror(ctypes.get_errno())}\n')
        return 2

    error_reader, error_writer = os.pipe2(os.O_CLOEXEC)
    started_at = time.monotonic()
    child_id = os.fork()
    if child_id == 0:
        for signal_number in (signal.SIGPIPE, signal.SIGXFSZ):
            signal.signal(signal_number, signal.SIG_DFL)
        os.closerange(3, error_writer)
        os.closerange(error_writer + 1, os.sysconf('SC_OPEN_MAX'))
        try:
            confine(library, readable_paths, writable_paths)
        except OSError as error:
            sys.stderr.write(f'Unable to confine the attempt: {error.strerror}\n')
            os.write(error_writer, error.strerror.encode('utf-8'))
            os._exit(126)
        try:
            os.execvp(command[0], command)
        except OSError as error:
            sys.stderr.write(f'Unable to run {command[0]}: {error.strerror}\n')
            os._exit(127)

    os.close(error_writer)
    child_status, stopped_by = wait_for_child(child_id, timeout_seconds, started_at, limits)
    finished_at = time.monotonic()
    timed_out = stopped_by == 'timed_out'

    stop_and_reap_remaining()
    usage = resource.getrusage(resource.RUSAGE_CHILDREN)
    write_measurement(measurement_descriptor, {
        'confinement_error': read_confinement_error(error_reader),
        'timed_out': timed_out,
        'limit_exceeded': None if timed_out else stopped_by,
        'wall_time_seconds': finished_at - started_at,
        'cpu_user_seconds': usage.ru_utime,
        'cpu_system_seconds': usage.ru_stime,
        'peak_resident_kibibytes': usage.ru_maxrss,
    })

    if child_status is None:
        os.kill(os.getpid(), signal.SIGKILL)
    if os.WIFSIGNALED(child_status):
        signal_number = os.WTERMSIG(child_status)
        if signal_number != signal.SIGKILL:
            signal.signal(signal_number, signal.SIG_DFL)
        os.kill(os.getpid(), signal_number)
    return os.waitstatus_to_exitcode(child_status)


if __name__ == '__main__':
    sys.exit(main())
