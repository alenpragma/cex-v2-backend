<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ResponseFormatTrait
{
    /**
     * Summary of responseData
     * @param bool $success
     * @param string $message
     * @param mixed $data
     * @return array{data: mixed, message: string, success: bool}
     */
    public function responseData(bool $success, string $message = '', mixed $data = null): array
    {
        $message = !$success && empty($message) ? __('Something went wrong! Please try again later') : $message;
        return ['success' => $success, 'message' => $message, 'data' => $data];
    }

    public function responseJsonData(bool $success, string $message = '', mixed $data = null): JsonResponse
    {
        $message = !$success && empty($message) ? __('Something went wrong! Please try again later') : $message;
        return response()->json(['success' => $success, 'message' => $message, 'data' => $data]);
    }
}
