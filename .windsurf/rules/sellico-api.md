# Правила интеграции с Sellico API

## Базовый URL

https://sellico.ru/api

## Аутентификация

- Использовать **Service Account** токен (пользователь с `is_service_account = true`)
- Токен передаётся в заголовке `Authorization: Bearer {service_account_token}`
- Regular user токены не работают для сервисных запросов

---

## Эндпоинт: Получение интеграций

**GET** `/get-integrations/{workspace?}`

- `workspace` — **обязателен** при пользовательских запросах (передавать всегда)
- Без `workspace_id` запрос к API **не выполнять** — возвращать ошибку 422
- Метод в сервисе: `SellicoApiService::getIntegrations(int $workspaceId)`

### Структура элемента ответа

```json
{
    "id": 1,
    "work_space_id": 123,
    "name": "Ozon Store",
    "type": "OZON",
    "description": "Описание",
    "account_status": "confirmed",
    "is_premium": false,
    "created_at": "...",
    "updated_at": "..."
}
```

### Типы интеграций (`type`)

- `OZON` → маппинг в `ozon`
- `WildBerries` → маппинг в `wildberries`
- `YandexMarket` → маппинг в `yandex_market`

### Статусы аккаунтов (`account_status`)

- `empty` — аккаунт пуст
- `added` — аккаунт добавлен
- `confirmed` — аккаунт подтверждён

### Маппинг полей ответа (обязательные поля в `getMarketplaceCredentials`)

```php
'id'             => $integration['id'],
'work_space_id'  => $integration['work_space_id'] ?? $integration['workspace_id'] ?? null,
'name'           => $integration['name'],
'type'           => $integration['type'],
'description'    => $integration['description'] ?? null,
'account_status' => $integration['account_status'] ?? null,
'is_premium'     => $integration['is_premium'] ?? false,
'api_key'        => $integration['api_key'] ?? null,
'client_id'      => $integration['client_id'] ?? null,
'created_at'     => $integration['created_at'] ?? null,
'updated_at'     => $integration['updated_at'] ?? null,
```

---

## Эндпоинт: Проверка прав пользователя

**GET** `/check-permission`

### Параметры (query)

- `token` — персональный токен пользователя (обязательный)
- `user` — ID пользователя (обязательный)
- `permission` — slug разрешения (обязательный)
- `workspace` — ID рабочего пространства (обязательный)

### Ответ

```json
{ "valid": true }
```

или

```json
{ "valid": false }
```

### Коды ошибок

- `401` — отсутствует или неверный service account токен
- `403` — пользователь не является service account
- `422` — невалидные параметры запроса

---

## Правила для кода

1. Перед вызовом `getIntegrations` всегда проверять наличие `$workspaceId` — если 0 или null, не делать HTTP-запрос
2. В `AuthController::integrations` проверять `$workspaceId` до обращения к сервису, возвращать 422 при отсутствии
3. Всегда включать поля `work_space_id`, `account_status`, `description` в маппинг интеграций
4. `check-permission` вызывается через GET с параметрами в query string
