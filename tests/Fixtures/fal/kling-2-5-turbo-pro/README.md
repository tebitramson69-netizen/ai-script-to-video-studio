# Captured fal responses — Kling 2.5 Turbo Pro

Recorded from a real generation on **2026-10-04**, endpoint
`fal-ai/kling-video/v2.5-turbo/pro/text-to-video`, prompt *"A calm river at
dawn, slow drifting mist"*, 5 seconds, 16:9. Cost $0.35.

These are **verbatim**, not edited to suit the tests. Every field name in
`FalResponseMapper` used to be a documented pattern rather than an observed one;
these three files are what turned the lifecycle from Tier B to Tier A.

| File | Tier A facts it carries |
| --- | --- |
| `01-submit.json` | `request_id`; `status_url` / `response_url` / `cancel_url` use the **first two segments** of the model id; submit answers `IN_QUEUE`; `metrics` is an empty **array** |
| `03-status-complete.json` | `COMPLETED`; the three URLs are **null** once finished; `metrics` is now an **object** carrying `inference_time` of 151.29s for a 5-second clip |
| `04-result.json` | the clip at `video.url` on a **different host** from the queue API, with `file_size`; **no cost field anywhere** |

Two values here are traps the mapper has to decline, and both are pinned by
tests:

- `metrics.inference_time: 151.28999996185303` must not be read as a duration.
  151 seconds in the timeline is a 2.5-minute clip.
- `video.file_size: 12418965` must not be read as a price. A recorded spend of
  $12.4m against a $10 cap.

A capture made with `studio:capture-fal-shapes` is already key-redacted by the
command's `save()`; nothing here needs scrubbing before it is committed.
