import ctypes
import json
import os
import resource
import select
import signal
import sys
import time

PR_SET_CHILD_SUBREAPER = 36
ORPHAN_REAPING_MILLISECONDS = 50


def processes_to_stop(own_id, group_id):
    processes = []
    for entry in os.listdir('/proc'):
        if not entry.isdigit() or int(entry) == own_id:
            continue
        try:
            with open(f'/proc/{entry}/stat', encoding='ascii', errors='replace') as stat_file:
                stat = stat_file.read()
        except OSError:
            continue
        fields = stat[stat.rindex(')') + 2:].split()
        if int(fields[1]) == own_id or int(fields[2]) == group_id:
            processes.append(int(entry))
    return processes


def stop_and_reap_remaining():
    own_id = os.getpid()
    group_id = os.getpgrp()
    while True:
        for process_id in processes_to_stop(own_id, group_id):
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


def wait_for_child(child_id, timeout_seconds, started_at):
    child_descriptor = os.pidfd_open(child_id)
    poller = select.poll()
    poller.register(child_descriptor, select.POLLIN)
    deadline = started_at + timeout_seconds
    try:
        while True:
            reaped_id, status, _ = os.wait4(-1, os.WNOHANG)
            while reaped_id != 0:
                if reaped_id == child_id:
                    return status
                reaped_id, status, _ = os.wait4(-1, os.WNOHANG)
            remaining_seconds = deadline - time.monotonic()
            if remaining_seconds <= 0:
                return None
            poller.poll(max(1, min(ORPHAN_REAPING_MILLISECONDS, int(remaining_seconds * 1000))))
    finally:
        os.close(child_descriptor)


def main():
    if len(sys.argv) < 5 or sys.argv[3] != '--':
        sys.stderr.write('Usage: measure-process-tree.py <timeout-seconds> <measurement-path> -- <command> [argument...]\n')
        return 2
    timeout_seconds = float(sys.argv[1])
    measurement_descriptor = open_measurement(sys.argv[2])
    command = sys.argv[4:]

    library = ctypes.CDLL(None, use_errno=True)
    if library.prctl(PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0) != 0:
        sys.stderr.write(f'Unable to become the subreaper of the attempt: {os.strerror(ctypes.get_errno())}\n')
        return 2

    started_at = time.monotonic()
    child_id = os.fork()
    if child_id == 0:
        for signal_number in (signal.SIGPIPE, signal.SIGXFSZ):
            signal.signal(signal_number, signal.SIG_DFL)
        try:
            os.execvp(command[0], command)
        except OSError as error:
            sys.stderr.write(f'Unable to run {command[0]}: {error.strerror}\n')
            os._exit(127)

    child_status = wait_for_child(child_id, timeout_seconds, started_at)
    finished_at = time.monotonic()
    timed_out = child_status is None

    stop_and_reap_remaining()
    usage = resource.getrusage(resource.RUSAGE_CHILDREN)
    write_measurement(measurement_descriptor, {
        'timed_out': timed_out,
        'wall_time_seconds': finished_at - started_at,
        'cpu_user_seconds': usage.ru_utime,
        'cpu_system_seconds': usage.ru_stime,
        'peak_resident_kibibytes': usage.ru_maxrss,
    })

    if timed_out:
        os.kill(os.getpid(), signal.SIGKILL)
    if os.WIFSIGNALED(child_status):
        signal_number = os.WTERMSIG(child_status)
        if signal_number != signal.SIGKILL:
            signal.signal(signal_number, signal.SIG_DFL)
        os.kill(os.getpid(), signal_number)
    return os.waitstatus_to_exitcode(child_status)


if __name__ == '__main__':
    sys.exit(main())
