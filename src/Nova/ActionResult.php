<?php

namespace NovaMcp\Nova;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\JsonResponse;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use NovaMcp\Mcp\ActionFailed;
use Stringable;

class ActionResult
{
    public static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! $value instanceof Stringable) {
            return null;
        }
        // Strip HTML and Unicode control/format characters, including bidi controls.
        $text = preg_replace('/[\\p{Cc}\\p{Cf}]/u', '', strip_tags((string) $value));

        return $text === null ? null : mb_substr($text, 0, $limit, 'UTF-8');
    }

    public function format(Action $action, mixed $response): array
    {
        if ($response instanceof JsonResponse) {
            $response = $response->getData(true);
        }
        if (app(ActionExposure::class)->fullResult($action)) {
            return ['result' => $response];
        }
        // Only inspect top-level Nova response keys. Never serialize URL, modal,
        // event or arbitrary nested objects merely to determine a result type.
        $data = $response instanceof ActionResponse ? $response->jsonSerialize() : $response;
        $data = is_array($data) ? $data : ($data instanceof \stdClass ? (array) $data : []);
        if (array_key_exists('danger', $data)) {
            throw new ActionFailed(['status' => 'failed', 'message' => self::text($data['danger'], 1000), 'type' => 'danger']);
        }
        if ($action instanceof ShouldQueue) {
            return ['result' => ['status' => 'queued']];
        }
        $type = 'none';
        foreach (['redirect', 'visit', 'download', 'modal', 'message'] as $key) {
            if (array_key_exists($key, $data)) {
                $type = $key;
                break;
            }
        }

        return ['result' => ['status' => 'completed', 'message' => self::text($data['message'] ?? null, 1000), 'type' => $type]];
    }
}
