"""Run one panel-prepared account, and report back what it is doing.

This file is shipped with GG LEVEL PRO and copied into every run folder, so
the bot source never has to carry a matching version of it.

A run folder, under ``data/processes/<user key>/<process id>/``:

    bot.py, tools.py, ff_udp.py ...   a copy of the bot source
    _base_config.py                   the bot's own config.py
    config.py                         generated - inherits the above, then
                                      overrides the settings the panel owns
    account_id.json                   uid and password
    access_token.json                 token
    run_process.py                    this file

and, once it is running:

    bot.pid                           the OS pid, kept across a renewal
    status.json                       what the panel reads
    bot.log                           the bot's own output, server side only

Everything the account needs is inside its own folder, so two of them can run
at once without sharing a byte of state. That matters because of two things in
the bot itself: ``account_file()`` resolves beside ``bot.py`` rather than
beside the caller, and the match counter is built from ``config.HowManyMatch``
at import time::

    COUNTER = Counter(config.HowManyMatch, not counted_run())

Which is also why this is a separate entry point rather than a flag: the
folder has to be on ``sys.path`` before ``bot`` is imported at all.

Usage, from inside a run folder:

    python run_process.py .

Exit codes: 0 ran or was stopped, 2 the folder is unusable, 1 the bot raised.
"""

import json
import os
import re
import signal
import sys
import threading
import time

REQUIRED = ("config.py", "bot.py", "account_id.json", "access_token.json")

_MATCH_BOTH = re.compile(r"^Match (\d+)/(\d+)\s*$")
_MATCH_ONE = re.compile(r"^Match (\d+)\s*$")

# Carried across a login renewal, which replaces this process image in place.
ENV_DONE = "GGLP_MATCHES_DONE"
ENV_XP = "GGLP_XP_START"
ENV_LEVEL = "GGLP_LEVEL_START"
ENV_RENEWALS = "GGLP_RENEWALS"

MAX_RENEWALS = 40
MIN_UPTIME_BEFORE_RENEWAL = 120.0


class Status:
    """The one file the panel reads.

    Deliberately not a copy of the bot's output. The panel shows this to whoever
    owns the account, and the bot's log says how the levelling is done - which
    lobby packets go out, which region, which anti-cheat hosts came back. So the
    message here is chosen from a short list of things it is fine for a user to
    read, and everything else stays in bot.log on the server.
    """

    def __init__(self, path):
        self._path = path
        self._lock = threading.Lock()
        self._data = {
            "pid": os.getpid(),
            "state": "starting",
            "matches_done": 0,
            "target_matches": 0,
            "remaining": 0,
            "unlimited": False,
            "account_id": "",
            "account_name": "",
            "region": "",
            "level_start": 0,
            "level_current": 0,
            "xp_start": 0,
            "xp_current": 0,
            "xp_gained": 0,
            "token_exp": 0,
            "renewals": int(os.environ.get(ENV_RENEWALS, "0") or 0),
            "message": "Starting up.",
            "started": int(time.time()),
            "updated": int(time.time()),
        }

    def get(self, key, default=None):
        with self._lock:
            return self._data.get(key, default)

    def update(self, **fields):
        with self._lock:
            changed = False
            for key, value in fields.items():
                if value is None:
                    continue
                if self._data.get(key) != value:
                    self._data[key] = value
                    changed = True
            if not changed:
                return
            target = int(self._data.get("target_matches") or 0)
            done = int(self._data.get("matches_done") or 0)
            self._data["remaining"] = (
                0 if self._data.get("unlimited") or not target
                else max(0, target - done)
            )
            xp_now = int(self._data.get("xp_current") or 0)
            xp_was = int(self._data.get("xp_start") or 0)
            self._data["xp_gained"] = (
                max(0, xp_now - xp_was) if xp_now and xp_was else 0
            )
            self._data["updated"] = int(time.time())
            payload = json.dumps(self._data, indent=2)
        self._write(payload)

    def _write(self, payload):
        # Write then rename: the panel reads this on every poll, and a
        # half-written file would parse as nothing at all.
        tmp = self._path + ".tmp"
        try:
            with open(tmp, "w", encoding="utf-8") as handle:
                handle.write(payload + "\n")
            if os.path.exists(self._path):
                os.remove(self._path)
            os.replace(tmp, self._path)
        except OSError:
            try:
                os.remove(tmp)
            except OSError:
                pass


def guard_folder(run_dir):
    missing = [n for n in REQUIRED if not os.path.isfile(os.path.join(run_dir, n))]
    if missing:
        # Stopping here beats letting bot.py fall back to whatever files sit
        # beside it and quietly level a different account.
        sys.stderr.write("run folder %s is missing: %s\n"
                         % (run_dir, ", ".join(missing)))
        return False
    return True


def install_hooks(bot, status, holder):
    """Watch the bot without editing it.

    Every progress line already goes through ``bot.emit``, and Python resolves
    that name on each call, so replacing it here catches all of them. Only the
    match counter is taken from those lines - the rest of what the bot prints
    goes to the log and no further.

    The SoloBot instance is caught on construction because everything else
    worth reporting - the name, the level, the xp, the token expiry - lives on
    it, and reading attributes costs nothing.
    """
    original_emit = bot.emit

    def emit(line):
        original_emit(line)
        both = _MATCH_BOTH.match(line)
        if both:
            done, target = int(both.group(1)), int(both.group(2))
            status.update(state="running", matches_done=done,
                          target_matches=target,
                          message="Played %d of %d matches." % (done, target))
            return
        one = _MATCH_ONE.match(line)
        if one:
            done = int(one.group(1))
            status.update(state="running", matches_done=done,
                          message="Played %d matches." % done)
        # Anything else is the bot talking about how it works. It stays in
        # bot.log; nothing about it reaches the panel.

    bot.emit = emit

    original_init = bot.SoloBot.__init__

    def __init__(self, *args, **kwargs):
        original_init(self, *args, **kwargs)
        # Seeded across a renewal so the run keeps one baseline and one match
        # count either side of a fresh login.
        try:
            carried_xp = int(os.environ.get(ENV_XP, "0") or 0)
            if carried_xp > 0:
                self._xp_start = carried_xp
            carried_level = int(os.environ.get(ENV_LEVEL, "0") or 0)
            if carried_level > 0:
                self._level_start = carried_level
        except Exception:
            pass
        holder["bot"] = self

    bot.SoloBot.__init__ = __init__


def seed_counter(bot, status):
    """Continue the match count after a login renewal.

    COUNTER is built at import time from HowManyMatch, so a renewed process
    would otherwise start again from zero and a 50-match run would never end.
    """
    try:
        carried = int(os.environ.get(ENV_DONE, "0") or 0)
    except Exception:
        carried = 0
    counter = getattr(bot, "COUNTER", None)
    if carried > 0 and counter is not None:
        counter._done = carried
        status.update(matches_done=carried)
        if not getattr(counter, "_unlimited", False) \
                and carried >= int(getattr(counter, "_target", 0)):
            counter._finished.set()


def sample(bot, status, holder):
    """Copy what the bot already knows into status.json.

    Nothing here makes a request. The counter and the profile are plain
    attributes the bot fills in at its own pace - the profile behind a rate
    limit of its own inside ``SoloBot.profile`` - so this can run every second
    without adding a single call to the game's API.
    """
    fields = {}

    counter = getattr(bot, "COUNTER", None)
    if counter is not None:
        fields["matches_done"] = int(getattr(counter, "_done", 0))
        fields["target_matches"] = int(getattr(counter, "_target", 0))
        fields["unlimited"] = bool(getattr(counter, "_unlimited", False))

    live = holder.get("bot")
    if live is not None:
        profile = getattr(live, "_profile", None) or {}
        account_id = getattr(live, "_account_id", None) or profile.get("account_id")
        if account_id:
            fields["account_id"] = str(account_id)
        if profile.get("nickname"):
            fields["account_name"] = str(profile["nickname"])
        if profile.get("region"):
            fields["region"] = str(profile["region"])
        for src, dst in (("level", "level_current"), ("xp", "xp_current"),
                         ("level_start", "level_start"), ("xp_start", "xp_start")):
            value = profile.get(src)
            if value is not None:
                fields[dst] = int(value)
        exp = int(getattr(live, "_token_exp", 0) or 0)
        if exp:
            fields["token_exp"] = exp

    if fields:
        status.update(**fields)
    return fields


def renew(run_dir, status, started_at):
    """Restart this process in place for a fresh login.

    ``os.execv`` replaces the program without changing the pid, so the pid file
    the panel is watching stays correct and the run reads as continuous rather
    than as a crash followed by a new start. The match count and the xp
    baseline travel in the environment, because both are rebuilt from scratch
    on the way back in.

    A new login is only obtainable by signing in again from the top; there is
    no refresh to call. Hence a whole process restart rather than a smaller
    repair.
    """
    renewals = int(status.get("renewals") or 0)
    if renewals >= MAX_RENEWALS:
        status.update(state="error",
                      message="Stopped after renewing the login %d times."
                              % renewals)
        return False
    if time.time() - started_at < MIN_UPTIME_BEFORE_RENEWAL:
        # A token that is already near expiry when the run begins would
        # otherwise put this into a restart loop, one every few seconds.
        return False

    env = dict(os.environ)
    env[ENV_DONE] = str(int(status.get("matches_done") or 0))
    env[ENV_XP] = str(int(status.get("xp_start") or 0))
    env[ENV_LEVEL] = str(int(status.get("level_start") or 0))
    env[ENV_RENEWALS] = str(renewals + 1)

    status.update(state="running",
                  renewals=renewals + 1,
                  message="Renewing the login and carrying on.")
    sys.stdout.write("renewing login (restart %d), carrying %s matches\n"
                     % (renewals + 1, env[ENV_DONE]))
    sys.stdout.flush()

    os.environ.update(env)
    try:
        os.execv(sys.executable, [sys.executable, os.path.abspath(__file__), run_dir])
    except Exception as exc:
        status.update(state="error", message="Could not renew the login.")
        sys.stderr.write("execv failed: %r\n" % (exc,))
        return False
    return True


def watcher(bot, status, holder, run_dir, stop_flag, started_at, margin):
    """Heartbeat, the cooperative half of Stop, and the three endings.

    Runs every second. It has to notice the finish itself, because ``_loop``
    ends with ``os._exit(0)`` - nothing after ``bot.main()`` gets a chance to
    run, and no ``finally`` fires either.
    """
    while not stop_flag.is_set():
        try:
            # The folder is gone.
            #
            # Someone deleted it by hand, or the panel removed the process
            # while a match was in flight. Either way this run is now the only
            # thing anywhere still holding those credentials, and it can no
            # longer be stopped from outside - bot.pid went with the folder.
            # A run in that state plays on for hours with nobody able to see
            # it or kill it, which is exactly the leak worth avoiding. Checked
            # first, before anything tries to write into the folder.
            if not os.path.isdir(run_dir) \
                    or not os.path.isfile(os.path.join(run_dir, "account_id.json")):
                sys.stderr.write("run folder is gone, stopping\n")
                sys.stderr.flush()
                wipe(holder)
                os._exit(0)

            sample(bot, status, holder)

            if os.path.exists(os.path.join(run_dir, "stop.flag")):
                status.update(state="stopped", message="Stopped.")
                cleanup(run_dir)
                wipe(holder)
                # bot.main() blocks in socket reads on daemon threads with no
                # cancellation of their own, so there is nothing to unwind.
                os._exit(0)

            counter = getattr(bot, "COUNTER", None)
            finished = counter is not None and counter.finished()

            if finished:
                done = int(getattr(counter, "_done", 0))
                target = int(getattr(counter, "_target", 0))
                if status.get("state") != "done":
                    status.update(state="done",
                                  message="Finished %d of %d matches."
                                          % (done, target))
                # Not exiting here: _loop still has a closing xp reading and a
                # shutdown delay to get through, and that reading is the whole
                # point of "xp after". The loop ends the process itself.
            else:
                # The login is about to run out and there is still work left,
                # so sign in again before it does rather than after.
                exp = int(status.get("token_exp") or 0)
                if exp and time.time() >= exp - margin:
                    if renew(run_dir, status, started_at):
                        return
        except Exception:
            pass
        stop_flag.wait(1.0)


def wipe(holder):
    """Drop the credentials this run is holding.

    Python will not let a string be scrubbed in place, so this cannot promise
    the bytes are gone from the heap. What it can do is remove every reference
    the process still has to them, so nothing later in this interpreter - a
    traceback, a repr, a crash dump - can print them back out.
    """
    live = holder.get("bot")
    if live is None:
        return
    for name in ("_password", "_access_token", "_token", "_uid"):
        try:
            setattr(live, name, "")
        except Exception:
            pass
    try:
        live._profile = {}
    except Exception:
        pass


def cleanup(run_dir):
    for name in ("bot.pid", "stop.flag"):
        try:
            os.remove(os.path.join(run_dir, name))
        except OSError:
            pass


def main():
    run_dir = os.path.abspath(sys.argv[1] if len(sys.argv) > 1
                              else os.path.dirname(os.path.abspath(__file__)))
    if not os.path.isdir(run_dir) or not guard_folder(run_dir):
        return 2

    os.environ["GGLP_RUN_DIR"] = run_dir
    while run_dir in sys.path:
        sys.path.remove(run_dir)
    sys.path.insert(0, run_dir)

    try:
        import config
    except Exception as exc:
        sys.stderr.write("cannot load config.py: %r\n" % (exc,))
        return 2

    loaded = os.path.abspath(getattr(config, "__file__", ""))
    if os.path.dirname(loaded) != run_dir:
        sys.stderr.write("config.py came from %s, expected %s\n"
                         % (loaded, run_dir))
        return 2

    status = Status(os.path.join(run_dir, "status.json"))
    status.update(target_matches=int(getattr(config, "HowManyMatch", 0)),
                  message="Signing in.")

    with open(os.path.join(run_dir, "bot.pid"), "w", encoding="utf-8") as handle:
        handle.write(str(os.getpid()))

    stop_flag = threading.Event()
    holder = {}
    started_at = time.time()
    margin = float(getattr(config, "Token_Renew_Margin", 300.0))

    def on_signal(signum, frame):
        status.update(state="stopped", message="Stopped.")
        stop_flag.set()
        cleanup(run_dir)
        wipe(holder)
        os._exit(0)

    for name in ("SIGTERM", "SIGINT", "SIGBREAK"):
        number = getattr(signal, name, None)
        if number is not None:
            try:
                signal.signal(number, on_signal)
            except (ValueError, OSError):
                pass

    sys.stdout.write("run folder %s  matches=%s safe=%s guest=%s token=%s renewals=%s\n"
                     % (run_dir,
                        getattr(config, "HowManyMatch", "?"),
                        getattr(config, "Safe_Mode", "?"),
                        getattr(config, "isGuest", "?"),
                        getattr(config, "IsAccessToken", "?"),
                        os.environ.get(ENV_RENEWALS, "0")))
    sys.stdout.flush()

    try:
        import bot
    except Exception as exc:
        # A missing dependency lands here, and it is by far the most common
        # first failure: the web server's PATH is rarely the shell's, so the
        # interpreter the panel launched may not be the one with the packages.
        status.update(state="error", message="The bot could not start.")
        cleanup(run_dir)
        sys.stderr.write("import bot failed: %r\n" % (exc,))
        return 1

    install_hooks(bot, status, holder)
    seed_counter(bot, status)
    threading.Thread(
        target=watcher,
        args=(bot, status, holder, run_dir, stop_flag, started_at, margin),
        daemon=True).start()

    code = 0
    try:
        bot.main()
        counter = getattr(bot, "COUNTER", None)
        done = int(getattr(counter, "_done", 0)) if counter else 0
        target = int(getattr(counter, "_target", 0)) if counter else 0
        if target and done >= target:
            status.update(state="done", matches_done=done,
                          message="Finished %d of %d matches." % (done, target))
        else:
            # main() returning early is how a bad token or a missing account
            # file shows up: no exception, just nothing to do.
            status.update(state="error", matches_done=done,
                          message="The bot stopped after %d match(es)." % done)
            code = 1
    except KeyboardInterrupt:
        status.update(state="stopped", message="Stopped.")
    except Exception as exc:
        status.update(state="error", message="The bot stopped unexpectedly.")
        sys.stderr.write("bot failed: %r\n" % (exc,))
        sys.stderr.flush()
        code = 1
    finally:
        stop_flag.set()
        cleanup(run_dir)
    return code


if __name__ == "__main__":
    raise SystemExit(main())
