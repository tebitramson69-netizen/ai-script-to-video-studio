/**
 * Live pipeline progress, by polling.
 *
 * WHY POLLING and not websockets or server-sent events. This deploys on XAMPP
 * and Apache with PHP's default file-based sessions, and both alternatives are a
 * poor fit for that:
 *
 *   - Laravel Reverb is a long-running PHP process on its own port. It is the
 *     right answer on a VPS you control and the wrong one on shared hosting,
 *     which is where this is going.
 *   - Server-sent events work on plain PHP (Laravel 13 even ships
 *     response()->eventStream()), but every open stream holds one Apache worker
 *     AND one session file lock for its whole life. Two tabs open on a small
 *     worker pool and the app deadlocks on its own session file.
 *
 * And the decisive reason is not infrastructure: the stages here take MINUTES.
 * A render is 30 seconds a clip; narration is seconds; assembly is an ffmpeg
 * pass. Sub-second delivery buys a human watching that nothing at all. Three
 * seconds is indistinguishable, and it costs one cheap GET.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: re-render the page. The status payload
 * updates a small strip in place, and when a stage finishes the page RELOADS.
 * Rebuilding shot cards, cost tables and warning banners in JavaScript would
 * mean two rendering paths for the same data, and the one nobody looks at drifts
 * from the one they do.
 */
(function () {
    'use strict';

    var config = readConfig();

    if (!config) {
        return;
    }

    // Base cadence while work is visibly happening. Fast enough to feel live,
    // slow enough that the session lock is free almost all of the time.
    var BASE_MS = 3000;

    // A quiet project backs off to here. Reached in about six polls.
    var IDLE_CEILING_MS = 30000;

    // A failing endpoint backs off further and separately, so a server hiccup
    // does not look like an idle project.
    var ERROR_CEILING_MS = 60000;

    // Consecutive failures tolerated before the strip says so and gives up.
    // Lying about being live is worse than admitting it stopped.
    var MAX_FAILURES = 5;

    // How long to keep watching a quiet project before concluding nothing is
    // coming. Counted in polls at the ceiling, so roughly a minute of silence.
    var QUIET_POLLS_BEFORE_STOP = 2;

    var el = {
        strip: document.getElementById('progress-strip'),
        text: document.getElementById('progress-text'),
        bar: document.getElementById('progress-bar-fill'),
        spend: document.getElementById('progress-spend'),
    };

    var state = {
        interval: BASE_MS,
        timer: null,
        inFlight: null,
        failures: 0,
        quietPolls: 0,
        wasBusy: false,
        fingerprint: config.fingerprint || null,
        stopped: false,
    };

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            // Nobody is looking. Stop spending their data and their battery —
            // this is the single biggest saving available, and browsers throttle
            // background timers unpredictably anyway, so a paused loop is also a
            // more predictable one.
            cancelTimer();
            abortInFlight();

            return;
        }

        if (!state.stopped) {
            // Back from a hidden tab: poll at once rather than waiting out the
            // old interval, because the answer is probably stale by minutes.
            state.interval = BASE_MS;
            state.quietPolls = 0;
            schedule(0);
        }
    });

    start();

    function start() {
        if (el.strip) {
            el.strip.hidden = false;
        }

        // Immediately, not after one interval. The common case is an owner who
        // just pressed a button and is watching for a reaction.
        schedule(0);
    }

    function schedule(delay) {
        cancelTimer();
        state.timer = window.setTimeout(poll, delay);
    }

    function cancelTimer() {
        if (state.timer !== null) {
            window.clearTimeout(state.timer);
            state.timer = null;
        }
    }

    function abortInFlight() {
        if (state.inFlight) {
            state.inFlight.abort();
            state.inFlight = null;
        }
    }

    function stop(message) {
        state.stopped = true;
        cancelTimer();
        abortInFlight();

        if (message) {
            setText(message);
            if (el.strip) {
                el.strip.classList.add('progress-paused');
            }
        } else if (el.strip) {
            el.strip.hidden = true;
        }
    }

    function poll() {
        // One request at a time. Without this an endpoint that has started
        // responding slowly would accumulate overlapping requests, each one
        // holding the session lock, until the app appeared to hang.
        abortInFlight();

        var controller = new AbortController();
        state.inFlight = controller;

        // A request that never returns must not wedge the loop.
        var timeout = window.setTimeout(function () {
            controller.abort();
        }, 10000);

        window.fetch(config.url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(function (response) {
                if (response.status === 429) {
                    // We are polling harder than the server wants. Honour what it
                    // asked for rather than guessing.
                    var after = parseInt(response.headers.get('Retry-After') || '', 10);
                    throw { retryAfterMs: (isFinite(after) ? after : 60) * 1000 };
                }

                if (response.status === 401 || response.status === 403 || response.status === 419) {
                    // The session went away. Polling forever against a login
                    // redirect is the classic way a "live" page silently dies.
                    stop('Session expired — reload the page to keep watching.');

                    return null;
                }

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            })
            .then(function (data) {
                if (data) {
                    apply(data);
                }
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }

                onFailure(error);
            })
            .finally(function () {
                window.clearTimeout(timeout);
                state.inFlight = null;
            });
    }

    function apply(data) {
        state.failures = 0;
        render(data);

        var changed = data.fingerprint !== state.fingerprint;
        state.fingerprint = data.fingerprint;

        // A stage finished, or the project moved on, or the export landed. The
        // server-rendered page is now out of date in ways a strip cannot express
        // — new shot thumbnails, a new cost table, a download button.
        if (shouldReload(data, changed)) {
            stop(null);
            window.location.reload();

            return;
        }

        if (data.busy) {
            state.wasBusy = true;
            state.interval = BASE_MS;
            state.quietPolls = 0;
            schedule(state.interval);

            return;
        }

        if (changed) {
            // Something moved on an idle project — another tab, or a queued job
            // that finished between polls. Worth staying attentive.
            state.interval = BASE_MS;
            state.quietPolls = 0;
            schedule(state.interval);

            return;
        }

        // Nothing is happening. Back off geometrically rather than hammering a
        // project that may sit untouched for an hour.
        if (state.interval >= IDLE_CEILING_MS) {
            state.quietPolls += 1;

            if (state.quietPolls >= QUIET_POLLS_BEFORE_STOP) {
                stop(null);

                return;
            }
        }

        state.interval = Math.min(Math.round(state.interval * 1.5), IDLE_CEILING_MS);
        schedule(state.interval);
    }

    function shouldReload(data, changed) {
        if (!changed) {
            return false;
        }

        // Structural changes only. Reloading on every fingerprint change would
        // refresh the page each time one shot of six finished, throwing away the
        // owner's scroll position and any half-typed scene edit.
        return data.status !== config.status
            || (data.assets && data.assets.final && !config.hasFinal)
            || (state.wasBusy && !data.busy);
    }

    function onFailure(error) {
        state.failures += 1;

        if (error && error.retryAfterMs) {
            setText('Live updates rate-limited — retrying shortly.');
            state.interval = error.retryAfterMs;
            schedule(state.interval);

            return;
        }

        if (state.failures >= MAX_FAILURES) {
            stop('Live updates stopped — refresh to see the latest.');

            return;
        }

        state.interval = Math.min(Math.round(Math.max(state.interval, BASE_MS) * 2), ERROR_CEILING_MS);
        schedule(state.interval);
    }

    function render(data) {
        var shots = data.shots || {};
        var total = shots.total || 0;
        var done = shots.rendered || 0;

        if (el.bar && total > 0) {
            el.bar.style.width = Math.round((done / total) * 100) + '%';
        }

        setText(describe(data, total, done));

        if (el.spend && typeof data.spent_usd === 'number') {
            el.spend.textContent = '$' + data.spent_usd.toFixed(2)
                + ' of $' + (data.budget_cap_usd || 0).toFixed(2);
        }

        if (el.strip) {
            el.strip.classList.toggle('progress-busy', !!data.busy);
            el.strip.classList.remove('progress-paused');
        }
    }

    function describe(data, total, done) {
        var parts = [];

        if (total > 0) {
            parts.push(done + ' of ' + total + ' shots rendered');
        }

        if (data.shots && data.shots.in_flight > 0) {
            parts.push(data.shots.in_flight + ' in progress');
        }

        if (data.shots && data.shots.failed > 0) {
            parts.push(data.shots.failed + ' failed');
        }

        if (data.provider_requests_in_flight > 0) {
            parts.push(data.provider_requests_in_flight + ' provider request(s) open');
        }

        if (parts.length === 0) {
            parts.push(data.status_label || 'Idle');
        }

        return (data.busy ? 'Working — ' : '') + parts.join(' · ');
    }

    /**
     * textContent, never innerHTML. Everything here arrives from JSON the server
     * built, and the one discipline that keeps that safe regardless of what a
     * project title or an error message contains is never treating it as markup.
     */
    function setText(message) {
        if (el.text) {
            el.text.textContent = message;
        }
    }

    function readConfig() {
        var node = document.getElementById('progress-config');

        if (!node) {
            return null;
        }

        try {
            return JSON.parse(node.textContent);
        } catch (e) {
            // A malformed island must leave a working page behind, not a broken
            // one. The server-rendered content is already correct.
            return null;
        }
    }
})();
