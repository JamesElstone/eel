<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ActivityStore
{
    public function recordFlashMessages(
        string $pageId,
        RequestFramework $request,
        ActionResultFramework $actionResult,
        ?int $userId = null
    ): void {
        $messages = $this->normaliseFlashMessages($actionResult->flashMessages());
        if ($messages === [] || !InterfaceDB::tableExists('application_activity_flash_history')) {
            return;
        }

        $metadata = $this->requestMetadata($request);

        foreach ($messages as $message) {
            InterfaceDB::prepareExecute(
                'INSERT INTO application_activity_flash_history (
                    user_id,
                    page_id,
                    action_name,
                    card_action_name,
                    message_type,
                    message_text,
                    message_html_text,
                    request_method,
                    is_ajax,
                    device_id,
                    ip_address,
                    user_agent,
                    request_uri
                ) VALUES (
                    :user_id,
                    :page_id,
                    :action_name,
                    :card_action_name,
                    :message_type,
                    :message_text,
                    :message_html_text,
                    :request_method,
                    :is_ajax,
                    :device_id,
                    :ip_address,
                    :user_agent,
                    :request_uri
                )',
                [
                    'user_id' => $userId !== null && $userId > 0 ? $userId : null,
                    'page_id' => $this->normaliseRequiredString($pageId, 255),
                    'action_name' => $this->normaliseOptionalString($request->action(), 255),
                    'card_action_name' => $this->normaliseOptionalString($request->cardAction(), 255),
                    'message_type' => $message['type'],
                    'message_text' => $message['text'],
                    'message_html_text' => $message['html_text'],
                    'request_method' => $this->normaliseOptionalString($request->method(), 10),
                    'is_ajax' => $request->isAjax() ? 1 : 0,
                    'device_id' => $metadata['device_id'],
                    'ip_address' => $metadata['ip_address'],
                    'user_agent' => $metadata['user_agent'],
                    'request_uri' => $metadata['request_uri'],
                ]
            );
        }
    }

    public function recordApiActivity(
        string $pageId,
        string $actionName,
        string $messageType,
        string $messageText,
        ?int $userId = null,
        array $metadata = [],
        ?string $cardActionName = null,
        ?string $requestMethod = null,
        ?string $requestUri = null
    ): void {
        $messageText = $this->plainText($messageText);
        if ($messageText === '' || !InterfaceDB::tableExists('application_activity_flash_history')) {
            return;
        }

        InterfaceDB::prepareExecute(
            'INSERT INTO application_activity_flash_history (
                user_id,
                page_id,
                action_name,
                card_action_name,
                message_type,
                message_text,
                message_html_text,
                request_method,
                is_ajax,
                device_id,
                ip_address,
                user_agent,
                request_uri
            ) VALUES (
                :user_id,
                :page_id,
                :action_name,
                :card_action_name,
                :message_type,
                :message_text,
                NULL,
                :request_method,
                0,
                :device_id,
                :ip_address,
                :user_agent,
                :request_uri
            )',
            [
                'user_id' => $userId !== null && $userId > 0 ? $userId : null,
                'page_id' => $this->normaliseRequiredString($pageId, 255),
                'action_name' => $this->normaliseOptionalString($actionName, 255),
                'card_action_name' => $this->normaliseOptionalString($cardActionName, 255),
                'message_type' => strtolower(trim($messageType)) === 'error' ? 'error' : 'success',
                'message_text' => $messageText,
                'request_method' => $this->normaliseOptionalString($requestMethod, 10),
                'device_id' => $this->normaliseOptionalString($metadata['device_id'] ?? null, 64),
                'ip_address' => $this->normaliseOptionalString($metadata['ip_address'] ?? null, 45),
                'user_agent' => $this->normaliseOptionalString($metadata['user_agent'] ?? null, 1000),
                'request_uri' => $this->normaliseOptionalString($requestUri, 2048),
            ]
        );
    }

    private function normaliseFlashMessages(array $flashMessages): array
    {
        $messages = [];

        foreach ($flashMessages as $flashMessage) {
            $message = $this->normaliseFlashMessage($flashMessage);
            if ($message === null) {
                continue;
            }

            $messages[] = $message;
        }

        return $messages;
    }

    private function normaliseFlashMessage(mixed $flashMessage): ?array
    {
        if (!is_array($flashMessage)) {
            $text = $this->plainText((string)$flashMessage);

            return $text === '' ? null : [
                'type' => 'success',
                'text' => $text,
                'html_text' => null,
            ];
        }

        $type = strtolower(trim((string)($flashMessage['type'] ?? 'success')));
        $type = $type === 'error' ? 'error' : 'success';
        $messageText = $this->plainText((string)($flashMessage['message'] ?? ''));
        $htmlText = null;

        if (array_key_exists('message_html', $flashMessage)) {
            $htmlText = $this->plainTextFromHtml((string)($flashMessage['message_html'] ?? ''));
        }

        $text = $messageText !== '' ? $messageText : (string)$htmlText;

        return $text === '' ? null : [
            'type' => $type,
            'text' => $text,
            'html_text' => $htmlText === '' ? null : $htmlText,
        ];
    }

    private function requestMetadata(RequestFramework $request): array
    {
        $deviceId = null;

        try {
            $deviceId = AntiFraudService::instance()->requestValue('Client-Device-ID');
        } catch (Throwable) {
        }

        return [
            'device_id' => $this->normaliseOptionalString($deviceId, 64),
            'ip_address' => $this->normaliseOptionalString((new ReverseProxyService())->clientIpAddress($request), 45),
            'user_agent' => $this->normaliseOptionalString($request->header('User-Agent', $request->server('HTTP_USER_AGENT', '')), 1000),
            'request_uri' => $this->normaliseOptionalString($request->server('REQUEST_URI', ''), 2048),
        ];
    }

    private function plainTextFromHtml(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;

        return $this->plainText(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function plainText(string $text): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }

    private function normaliseRequiredString(mixed $value, int $maxLength): string
    {
        $value = $this->normaliseOptionalString($value, $maxLength);

        return $value ?? '';
    }

    private function normaliseOptionalString(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }
}
