# PlaceSales API

Это API для управления рабочими пространствами, клиентами, проектами и задачами. Оно позволяет пользователям регистрироваться, создавать команды, управлять доступом и отслеживать рабочий процесс.

## Начало работы

Инструкции по установке зависимостей и запуску проекта локально.

```bash
# 1. Клонировать репозиторий
git clone https://your-repository-url/placesales_api.git
cd placesales_api

# 2. Установить зависимости
composer install

# 3. Настроить окружение
cp .env.example .env

# 4. Сгенерировать ключ приложения
php artisan key:generate

# 5. Выполнить миграции и сиды (если есть)
php artisan migrate --seed

# 6. Запустить локальный сервер
php artisan serve
```

## Базовый URL

Все эндпоинты API доступны по следующему базовому URL:

```
https://sellico.ru/api
```

## Аутентификация

API использует аутентификацию на основе токенов Laravel Sanctum. Для доступа к защищенным роутам необходимо передавать `Bearer` токен в заголовке `Authorization`.

**Пример заголовка:**
```http
Authorization: Bearer {your_api_token}
Accept: application/json
```

---

## Эндпоинты API

### Аутентификация

#### 1. Регистрация нового пользователя
-   **URL:** `/register`
-   **Метод:** `POST`
-   **Тело запроса:**
    ```json
    {
        "name": "John Doe",
        "email": "john.doe@example.com",
        "password": "password",
        "password_confirmation": "password"
    }
    ```
-   **Успешный ответ (Код `201 CREATED`):**
    ```json
    {
        "user": {
            "name": "John Doe",
            "email": "john.doe@example.com",
            "updated_at": "2024-08-03T12:00:00.000000Z",
            "created_at": "2024-08-03T12:00:00.000000Z",
            "id": 1
        },
        "access_token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxx",
        "token_type": "Bearer"
    }
    ```

#### 2. Вход в систему
-   **URL:** `/login`
-   **Метод:** `POST`
-   **Тело запроса:**
    ```json
    {
        "email": "john.doe@example.com",
        "password": "password"
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "user": { ... },
        "access_token": "2|yyyyyyyyyyyyyyyyyyyyyyyyyyyy",
        "token_type": "Bearer"
    }
    ```

#### 3. Запрос на сброс пароля
-   **URL:** `/forgot-password`
-   **Метод:** `POST`
-   **Тело запроса:**
    ```json
    {
        "email": "john.doe@example.com"
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    *На указанный email будет отправлено письмо со ссылкой для сброса пароля.*
    ```json
    {
        "message": "Ссылка для сброса пароля отправлена на ваш email!"
    }
    ```

#### 4. Сброс пароля
-   **URL:** `/reset-password`
-   **Метод:** `POST`
-   **Тело запроса:**
    *`token` и `email` берутся из ссылки, полученной в письме.*
    ```json
    {
        "token": "токен_из_письма",
        "email": "john.doe@example.com",
        "password": "new_password",
        "password_confirmation": "new_password"
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Ваш пароль был сброшен!"
    }
    ```

#### 4.1. Смена пароля
-   **URL:** `/change-password`
-   **Метод:** `POST`
-   **Аутентификация:** Требуется.
-   **Тело запроса:**
    ```json
    {
        "current_password": "old_password",
        "password": "new_secure_password",
        "password_confirmation": "new_secure_password"
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Password changed successfully"
    }
    ```

#### 4.2. Обновление профиля пользователя
-   **URL:** `/profile`
-   **Метод:** `PUT`
-   **Аутентификация:** Требуется.
-   **Тело запроса:**
    ```json
    {
        "name": "Updated Name",
        "phone": "+1234567890",
        "position": "Developer",
        "department": "IT",
        "about": "Some info about me"
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Profile updated successfully",
        "user": {
            "id": 1,
            "name": "Updated Name",
            "email": "john.doe@example.com",
            "phone": "+1234567890",
            "position": "Developer",
            "department": "IT",
            "about": "Some info about me"
        }
    }
    ```
#### 4.3. Загрузить изображение профиля
- **URL:** `/profile/image`
- **Метод:** `POST`
- **Аутентификация:** Требуется.
- **Формат запроса:** `multipart/form-data`
- **Параметры:**
  - `image`: (file, required) Изображение (jpeg, png, jpg, gif, webp), макс. 2MB
- **Успешный ответ (Код `201 CREATED`):**
  ```json
  {
      "message": "Profile image uploaded successfully",
      "image_path": "profile_images/1/550e8400-e29b-41d4-a716-446655440000.jpg",
      "image_url": "[https://example.com/storage/profile_images/1/550e8400-e29b-41d4-a716-446655440000.jpg](https://example.com/storage/profile_images/1/550e8400-e29b-41d4-a716-446655440000.jpg)",
      "user": {
          "id": 1,
          "name": "John Doe",
          "email": "john@example.com",
          "image": "profile_images/1/550e8400-e29b-41d4-a716-446655440000.jpg",
          ...
      }
  }

#### 4.4. Удалить изображение профиля
- **URL:** `/profile/image`
- **Метод:** `DELETE`
- **Аутентификация:** Требуется.
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "message": "Profile image deleted successfully",
      "user": {
          "id": 1,
          "name": "John Doe",
          "email": "john@example.com",
          "image": null,
          ...
      }
  }

#### 4.5. Запрос на смену email
- **URL:** `/profile/email/change-request`
- **Метод:** `POST`
- **Аутентификация:** Требуется.
- **Описание:** Создает запрос на смену email и отправляет письмо с подтверждением на новый email.
- **Тело запроса:**
  ```json
  {
      "new_email": "new.email@example.com",
      "password": "current_password"
  }
- **Успешный ответ (Код `200 OK`):**
  ```json
    {
        "message": "Письмо с подтверждением отправлено"
    }
- **Ошибки:**
  ```json
    {
        "message": "Неверный пароль"
    }
    ```

#### 4.6. Подтверждение смены email
- **URL:** `/profile/email/verify`
- **Метод:** `POST`
- **Аутентификация:** Не требуется (публичный эндпоинт).
- **Описание:** Подтверждает смену email по токену из письма.
- **Тело запроса:**
  ```json
    {
        "token": "verification_token_from_email"
    }
- **Успешный ответ (Код `200 OK`):**
  ```json
    {
        "message": "Email успешно изменен"
    }
- **Ошибки:**
- **422 Unprocessable Entity: Недействительный или просроченный токен**
    ```json
    {
        "message": "Недействительный токен"
    }
    ```
#### 4.7. Отмена запроса на смену email
- **URL:** `/profile/email/change-request`
- **Метод:** `DELETE`
- **Аутентификация:** Требуется.
- **Описание:** Отменяет все активные запросы на смену email.
- **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Запрос на смену email отменен"
    }
    ```
#### 4.8. Проверка прав администратора
- **URL:** `/checkAdmin`
- **Метод:** `GET`
- **Аутентификация:** Требуется.
- **Описание:** Возвращает информацию, является ли текущий аутентифицированный пользователь администратором системы.
- **Успешный ответ (Код 200 OK):**
    ```json
    {
        "isAdmin": true
    }
    ```
    или
    ```json
    {
        "isAdmin": false
    }
    ```

#### 5. Получить данные аутентифицированного пользователя
-   **URL:** `/user`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "id": 1,
        "name": "John Doe",
        "email": "john.doe@example.com",
        "email_verified_at": null,
        "created_at": "2024-08-03T12:00:00.000000Z",
        "updated_at": "2024-08-03T12:00:00.000000Z"
    }
    ```

#### 6. Выход из системы
-   **URL:** `/logout`
-   **Метод:** `POST`
-   **Аутентификация:** Требуется.
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Successfully logged out"
    }
    ```

#### 6.1. Получить данные аутентифицированного пользователя
-   **URL:** `/workspaces/{workspace}/user-permissions`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Параметры URL:**
    -   `workspace` — ID рабочего пространства.
-   **Описание:** Возвращает информацию о роли и списке прав (permissions) текущего аутентифицированного пользователя в указанном рабочем пространстве.
-   **Успешный ответ для глобального администратора (Код `200 OK`):**
    ```json
    {
        "user_id": 1,
        "role": "admin",
        "permissions": [
            {
                "id": 1,
                "name": "Просмотр рабочих пространств",
                "slug": "workspaces.view",
                "created_at": "2026-02-20T10:00:00.000000Z",
                "updated_at": "2026-02-20T10:00:00.000000Z"
            },
            {
                "id": 2,
                "name": "Редактирование задач",
                "slug": "tasks.edit",
                "created_at": "2026-02-20T10:00:00.000000Z",
                "updated_at": "2026-02-20T10:00:00.000000Z"
            }
        ]
    }
    ```
-   **Успешный ответ для обычного пользователя с ролью в воркспейсе (Код `200 OK`):**
    ```json
    {
        "user_id": 5,
        "role": {
            "id": 3,
            "name": "Менеджер",
            "slug": "manager",
            "...": "..."
        },
        "permissions": [
            {
                "id": 2,
                "name": "Редактирование задач",
                "slug": "tasks.edit",
                "...": "..."
            }
        ]
    }
    ```
-   **Успешный ответ, если у участника нет назначенной роли в этом воркспейсе (Код `200 OK`):**
    ```json
    {
        "user_id": 5,
        "role": null,
        "permissions": []
    }
    ```
-   **Ошибки:**
    -   **404 Not Found:** Пользователь не является участником данного рабочего пространства
    ```json
    {
        "message": "User is not a member of this workspace."
    }
    ```
    -   **404 Not Found:** Указанная в связке роль не найдена
    ```json
    {
        "message": "Role not found for this workspace."
    }
    ```

#### 7. Проверка токена приглашения
-   **URL:** `/verify-invitation`
-   **Метод:** `POST`
-   **Аутентификация:** Не требуется.
-   **Описание:** Проверяет валидность токена приглашения и возвращает информацию о пользователе.
-   **Тело запроса:**
    ```json
    {
        "token": "invitation_token_string"
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "user": {
            "id": 1,
            "email": "user@example.com"
        },
        "token": "invitation_token_string"
    }
    ```
-   **Ошибка (Код `404 Not Found`):**
    ```json
    {
        "message": "Invalid or expired token"
    }
    ```
#### 8. Регистрация по приглашению
-   **URL:** `/register-by-invitation`
-   **Метод:** `POST`
-   **Аутентификация:** Не требуется.
-   **Описание:** Завершает регистрацию пользователя по токену приглашения, устанавливая имя и пароль.
-   **Тело запроса:**
    ```json
    {
        "token": "invitation_token_string",
        "name": "John Doe",
        "password": "secure_password",
        "password_confirmation": "secure_password"
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "user@example.com",
            "updated_at": "2026-01-27T10:00:00.000000Z",
            "created_at": "2026-01-27T10:00:00.000000Z"
        },
        "access_token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxx",
        "token_type": "Bearer"
    }
    ```
-   **Ошибки:**
    - **404 Not Found:** Недействительный или просроченный токен
    ```json
    {
        "message": "Invalid or expired token"
    }
    ```
    - **422 Unprocessable Entity:** Ошибки валидации
    ```json
    {
        "message": "The given data was invalid.",
        "errors": {
            "token": ["The token field is required."],
            "name": ["The name field is required."],
            "password": ["The password must be at least 8 characters."]
        }
    }
    ```
---

### Новости (News)

#### 1. Получить внутренние новости
-   **URL:** `/news/sellico`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Описание:** Получает список всех новостей.
-   **Заголовки:**
    - `Authorization: Bearer {token}`
    - `Accept: application/json`
-   **Требуемые права:** —
-   **Успешный ответ (Код `200 OK`):**
    ```json
    [
        {
            "id": 1,
            "title": "Важное обновление системы",
            "text": "Мы рады сообщить о выходе новой версии...",
            "date": "2025-11-26T10:00:00.000000Z",
            "tags": ["важное", "обновление"],
            "created_at": "2025-11-26T10:00:00.000000Z",
            "updated_at": "2025-11-26T10:00:00.000000Z"
        },
        {
            "id": 2,
            "title": "Техническое обслуживание",
            "text": "Плановое обслуживание сервера...",
            "date": "2025-11-27T15:30:00.000000Z",
            "tags": ["техническое", "обслуживание"],
            "created_at": "2025-11-27T15:30:00.000000Z",
            "updated_at": "2025-11-27T15:30:00.000000Z"
        }
    ]
    ```

#### 2. Получить новости Wildberries
-   **URL:** `/workspaces/{workspace}/news/wildberries`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Параметры URL:** `workspace` (ID рабочего пространства).
-   **Заголовки:**
    - `Authorization: Bearer {token}`
    - `Accept: application/json`
-   **Требуемые права:** —
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "success": true,
        "data": [
            {
                "title": "Заголовок новости",
                "description": "Текст новости...",
                "date": "2025-11-05T00:00:00+03:00",
                "tags": ["важное", "обновление"]
            }
        ]
    }
    ```
-   **Ошибка (Код `404 Not Found`):**
    ```json
    {
        "success": false,
        "message": "WildBerries integration not found"
    }
    ```

---

### Заявки (Application)

#### 1. Создание новой заявки
-   **URL:** `/applications`
-   **Метод:** `POST`
-   **Описание:** Создает заявки в рабочих пространствах с ID 1 и 3. Этот эндпоинт является публичным и не требует аутентификации.
-   **Требуемые права:** —
-   **Тело запроса:**
    ```json
    {
        "name": "Иван Петров",
        "phone": "+79001234567",
        "email": "ivan.petrov@example.com",
        "comment": "Хочу консультацию по интеграции"
    }
    ```
    *Поля `email` и `comment` являются необязательными.*
-   **Успешный ответ (Код `201 CREATED`):**
    ```json
    [
        {
            "id": 1,
            "name": "Иван Петров",
            "phone": "+79001234567",
            "email": "ivan.petrov@example.com",
            "comment": "Хочу консультацию по интеграции",
            "work_space_id": 1,
            "created_at": "2024-08-04T10:00:00.000000Z",
            "updated_at": "2024-08-04T10:00:00.000000Z"
        },
        {
            "id": 2,
            "name": "Иван Петров",
            "phone": "+79001234567",
            "email": "ivan.petrov@example.com",
            "comment": "Хочу консультацию по интеграции",
            "work_space_id": 3,
            "created_at": "2024-08-04T10:00:00.000000Z",
            "updated_at": "2024-08-04T10:00:00.000000Z"
        }
    ]
    ```

#### 2. Получение списка заявок
-   **URL:** `/workspaces/{workspace}/applications`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `applications.view`
-   **Описание:** Получает список всех заявок для указанного рабочего пространства.
-   **Параметры URL:** `workspace` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    [
        {
            "id": 1,
            "name": "Иван Петров",
            "phone": "+79001234567",
            "email": "ivan.petrov@example.com",
            "comment": null,
            "work_space_id": 1,
            "created_at": "2024-08-04T10:00:00.000000Z",
            "updated_at": "2024-08-04T10:00:00.000000Z"
        }
    ]
    ```

#### 3. Получение одной заявки
-   **URL:** `/workspaces/{workspace}/applications/{application}`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `applications.view`
-   **Описание:** Получает информацию о конкретной заявке.
-   **Параметры URL:** `workspace` (ID), `application` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "id": 1,
        "name": "Иван Петров",
        "phone": "+79001234567",
        "email": "ivan.petrov@example.com",
        "comment": null,
        "work_space_id": 1,
        "created_at": "2024-08-04T10:00:00.000000Z",
        "updated_at": "2024-08-04T10:00:00.000000Z"
    }
    ```

---

### Лиды (Leads)
#### 1. Создание лида
-   **URL:** `/workspaces/{workspace}/leads`
-   **Метод:** `POST`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `leads.edit`
-   **Описание:** Создает новый лид в указанном рабочем пространстве.
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Сделка с ООО Ромашка",
        "status": "new",
        "priority": "medium",
        "responsible_id": 12,
        "contact_name": "Иван Иванов",
        "position": "Директор",
        "contact_phone": "+79991234567",
        "contact_email": "contact@example.com",
        "company": "ООО Ромашка",
        "legal_name": "ООО Ромашка",
        "inn": "7700000000",
        "kpp": "770001001",
        "ogrn": "1234567890123",
        "website": "https://romashka.example.com",
        "legal_address": "Москва, ул. Примерная, д. 1",
        "funnel_stage_id": 10
    }
    ```
-   **Поля `status`: one of `new`, `negotiation`, `won`, `lost`**
-   **Поля `priority`: one of `low`, `medium`, `high`, `urgent`**
-   **`probability`: целое число 0–100**
-   **`amount`: число ≥ 0**
-   **Успешный ответ (Код `201 CREATED`):**
    ```json
    {
        "id": 1,
        "name": "Сделка с ООО Ромашка",
        "status": "new",
        "priority": "medium",
        "responsible_id": 12,
        "contact_name": "Иван Иванов",
        "position": "Директор",
        "contact_phone": "+79991234567",
        "contact_email": "contact@example.com",
        "company": "ООО Ромашка",
        "legal_name": "ООО Ромашка",
        "inn": "7700000000",
        "kpp": "770001001",
        "ogrn": "1234567890123",
        "website": "https://romashka.example.com",
        "legal_address": "Москва, ул. Примерная, д. 1",
        "work_space_id": 1,
        "funnel_stage_id": 10,
        "created_at": "2026-01-14T00:00:00.000000Z",
        "updated_at": "2026-01-14T00:00:00.000000Z"
    }
    ```
-   **Ошибки:**
    ```json
    422 Unprocessable Entity: неверные значения status/priority, валидация полей
    ```
#### 2. Список лидов рабочего пространства
-   **URL:** `/workspaces/{workspace}/leads`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `leads.view`
-   **Описание:** Возвращает список всех лидов указанного рабочего пространства.
-   **Параметры URL:** `workspace` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    [
        {
            "id": 1,
            "name": "Сделка с ООО Ромашка",
            "status": "new",
            "priority": "medium",
            "work_space_id": 1,
            "...": "..."
        },
        {
            "id": 2,
            "name": "Переговоры с ИП Петров",
            "status": "negotiation",
            "priority": "high",
            "work_space_id": 1
        }
    ]
    ```
#### 3. Получение одного лида
-   **URL:** `/workspaces/{workspace}/leads/{lead}`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `leads.view`
-   **Описание:** Получает данные конкретного лида в рамках указанного рабочего пространства.
-   **Параметры URL:** `workspace` (ID), `lead` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "id": 1,
        "name": "Сделка с ООО Ромашка",
        "status": "new",
        "priority": "medium",
        "work_space_id": 1,
        "...": "...",
        "tasks": 
    }
    ```
-   **Ошибки:**
    ```json
    403 Forbidden: доступ к чужому рабочему пространству
    ```
#### 4. Обновление лида
-   **URL:** `/workspaces/{workspace}/leads/{lead}`
-   **Метод:** `PUT`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `leads.edit`
-   **Описание:** Обновляет поля лида (любые поля опциональны).
-   **Тело запроса (пример):**
    ```json
    {
        "status": "won",
        "priority": "high",
        "probability": 90,
        "amount": 200000.00
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "id": 1,
        "status": "won",
        "priority": "high",
        "...": "..."
    }
    ```
#### 5. Удаление лида
-   **URL:** `/workspaces/{workspace}/leads/{lead}`
-   **Метод:** `DELETE`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `leads.delete`
-   **Описание:** Удаляет лид.
-   **Успешный ответ (Код `200 OK`):**
    ```json
    { "message": "Deleted" }
    ```

#### 6. Получить поля лида
-   **URL:** `/workspaces/{workspace}/leads/{lead}/deal-fields`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `leads.edit`
-   **Описание:** Получает все кастомные поля лида с их значениями.
-   **Параметры URL:** `workspace` (ID), `lead` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    [
        {
            "id": 1,
            "name": "Бюджет клиента",
            "type": "number",
            "placeholder": "Укажите бюджет",
            "status": true,
            "order": 1,
            "value": "150000.00"
        },
        {
            "id": 2,
            "name": "Источник лида",
            "type": "select",
            "placeholder": "Выберите источник",
            "status": true,
            "order": 2,
            "value": "Сайт"
        },
        {
            "id": 3,
            "name": "Дополнительные услуги",
            "type": "checkbox",
            "placeholder": "Выберите услуги",
            "status": true,
            "order": 3,
            "value": ["консультация", "поддержка"]
        }
    ]
    ```
#### 7. Обновить поля лида
-   **URL:** `/workspaces/{workspace}/leads/{lead}/deal-fields`
-   **Метод:** `PUT`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `leads.edit`
-   **Описание:** Обновляет значения кастомных полей лида.
-   **Параметры URL:** `workspace` (ID), `lead` (ID).
-   **Тело запроса:**
    ```json
    {
        "fields": [
            {
                "deal_field_id": 1,
                "value": "200000.00"
            },
            {
                "deal_field_id": 2,
                "value": "Рекомендация"
            },
            {
                "deal_field_id": 3,
                "value": ["консультация", "обучение", "поддержка"]
            }
        ]
    }
    ```
-   **Примечания:**
    - Для полей типа `checkbox` значение передается как массив
    - Для остальных типов значение передается как строка
    - Можно обновлять несколько полей одновременно
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Deal fields updated successfully"
    }
    ```
-   **Ошибки:**
    ```json
    422 Unprocessable Entity: неверные ID полей или отсутствует массив fields
    404 Not Found: поле из другого рабочего пространства
    403 Forbidden: недостаточно прав для редактирования
    ```
---

### Воронка продаж (Funnel Stages)
#### 1. Получить список этапов
-   **URL:** `/workspaces/{workspace}/funnel-stages`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `funnel-stages.view`
-   **Описание:** Возвращает этапы воронки, сгруппированные по stage_type (main, closing).
-   **Успешный ответ (Код 200 OK):**
    ```json
    {
        "main": [
            { "id": 1, "name": "Новый", "order": 1, "color": "#1E90FF", "stage_type": "main", "is_default": true, "work_space_id": 1, ... },
            { "id": 2, "name": "Переговоры", "order": 2, "color": "#FFA500", "stage_type": "main", "is_default": false, "work_space_id": 1, ... }
        ],
        "closing": [
            { "id": 3, "name": "Выиграно", "order": 3, "color": "#28A745", "stage_type": "closing", "is_default": false, "work_space_id": 1, ... },
            { "id": 4, "name": "Проиграно", "order": 4, "color": "#DC3545", "stage_type": "closing", "is_default": false, "work_space_id": 1, ... }
        ]
    }
    ```
#### 2. Создать этап
-   **URL:** `/workspaces/{workspace}/funnel-stages`
-   **Метод:** `POST`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `funnel-stages.edit`
-   **Тело запроса:**
    ```json
    {
        "name": "Первичный контакт",
        "order": 1,
        "color": "#00AAFF",
        "stage_type": "main",
        "description": "Описание этапа",
        "is_default": true
    }
    ```
#### Заметки:
-   `stage_type`: one of `main`, `closing`.
-   Если `is_default = true`, флаг дефолта автоматически снимается с остальных этапов этого рабочего пространства.
-   Успешный ответ (Код 201 CREATED): объект созданного этапа.
#### 3. Получить этап
-   **URL:** `/workspaces/{workspace}/funnel-stages/{funnelStage}`
-   **Метод:** `GET`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `funnel-stages.view`
-   **Успешный ответ (Код 200 OK):** объект этапа.
#### 4. Обновить этап
-   **URL:** `/workspaces/{workspace}/funnel-stages/{funnelStage}`
-   **Метод:** `PUT`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `funnel-stages.edit`
-   **Описание:** Обновляет поля этапа. Если передан `is_default: true`, флаг снимается со всех остальных этапов этого рабочего пространства.
-   **Успешный ответ (Код 200 OK):** объект обновленного этапа.
#### 5. Удалить этап
-   **URL:** `/workspaces/{workspace}/funnel-stages/{funnelStage}`
-   **Метод:** `DELETE`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `funnel-stages.delete`
-   **Ограничение:** Если существуют лиды, связанные с этим этапом, вернётся 422:
    ```json
    { "message": "Невозможно удалить этап: существуют лиды, привязанные к этому этапу." }
    ```
#### 6. Создать дефолтный набор этапов
-   **URL:** `/workspaces/{workspace}/funnel-stages/seed-defaults`
-   **Метод:** `POST`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `funnel-stages.edit`
-   **Описание:** Идемпотентно создаёт базовый набор этапов:
-   **Метод:** `POST`
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `funnel-stages.edit`
-   **Описание:** Идемпотентно создаёт базовый набор этапов:
-   **Новый (main, order = 1, is_default = true)**
-   **Переговоры (main, order = 2)**
-   **Выиграно (closing, order = 3)**
-   **Проиграно (closing, order = 4)**
-   **Успешный ответ** (Код `201 CREATED`):
    ```json
    {
    "message": "Default funnel stages seeded",
    "stages": [ { ... }, { ... }, { ... }, { ... } ]
    }
    ```

### Поля сделок (Deal Fields)
Эндпоинты для управления кастомными полями сделок (лидов) в рабочих пространствах. Позволяют создавать дополнительные поля для сбора специфической информации о сделках.
#### `GET /workspaces/{workspace}/deal-fields`
Получить список всех полей сделок для указанного рабочего пространства.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `workspaces.view`
-   **Параметры URL:** `workspace` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    [
        {
            "id": 1,
            "name": "Бюджет клиента",
            "type": "number",
            "placeholder": "Укажите бюджет",
            "status": true,
            "order": 1,
            "work_space_id": 1,
            "created_at": "2026-01-19T10:00:00.000000Z",
            "updated_at": "2026-01-19T10:00:00.000000Z"
        },
        {
            "id": 2,
            "name": "Источник лида",
            "type": "select",
            "placeholder": "Выберите источник",
            "status": true,
            "order": 2,
            "work_space_id": 1,
            "created_at": "2026-01-19T10:00:00.000000Z",
            "updated_at": "2026-01-19T10:00:00.000000Z"
        }
    ]
    ```
#### `POST /workspaces/{workspace}/deal-fields`
Создать новое поле для сделок.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `workspaces.update`
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Дата следующего контакта",
        "type": "date",
        "placeholder": "Выберите дату",
        "order": 3,
        "status": true
    }
    ```
-   **Поля `type`: one of `text`, `number`, `select`, `checkbox`, `date`, `radio`, `phone`, `email`, `url`**
-   **`order`: целое число ≥ 0 для сортировки полей**
-   **`status`: булево значение для активации/деактивации поля**
-   **Успешный ответ (Код `201 CREATED`):** объект созданного поля.
#### `GET /workspaces/{workspace}/deal-fields/{dealField}`
Получить информацию о конкретном поле сделок.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `workspaces.view`
-   **Параметры URL:** `workspace` (ID), `dealField` (ID).
-   **Успешный ответ (Код `200 OK`):** объект поля.
#### `PUT|PATCH /workspaces/{workspace}/deal-fields/{dealField}`
Обновить поле сделок.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `workspaces.update`
-   **Параметры URL:** `workspace` (ID), `dealField` (ID).
-   **Тело запроса:** (любое из полей, доступных при создании)
-   **Успешный ответ (Код `200 OK`):** объект обновленного поля.
#### `DELETE /workspaces/{workspace}/deal-fields/{dealField}`
Удалить поле сделок.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `workspaces.update`
-   **Параметры URL:** `workspace` (ID), `dealField` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    { "message": "Deleted" }
    ```
---

### Рабочие пространства (Workspaces)

#### `GET /workspaces`
Получить список всех рабочих пространств, к которым у пользователя есть доступ.
-   **Аутентификация:** Требуется.

#### `POST /workspaces`
Создать новое рабочее пространство.
-   **Аутентификация:** Требуется.
-   **Тело запроса (`multipart/form-data`):**
    -   `name`: (string, required) Название рабочего пространства.
    -   `description`: (string, optional) Описание.
    -   `logo`: (file, optional) Файл логотипа (изображение, макс. 2MB).

#### `GET /workspaces/{workspace}`
Получить информацию о конкретном рабочем пространстве.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `workspaces.view`
-   **Параметры URL:** `workspace` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "id": 1,
        "name": "My Workspace",
        "type": "trial",
        "description": "Описание рабочего пространства.",
        "logo_url": "https://sellico.ru/storage/workspaces/logos/logo.png",
        "user_id": 1,
        "created_at": "2024-08-07T10:00:00.000000Z",
        "updated_at": "2024-08-07T10:00:00.000000Z",
        "owner": {
            "id": 1,
            "name": "John Doe",
            "email": "john.doe@example.com",
            "has_account": true
        },
        "users": [
            { "id": 1, "name": "John Doe", "email": "john.doe@example.com", "has_account": true, "pivot": { ... } }
        ]
    }
    ```

#### `PUT|PATCH /workspaces/{workspace}`
Обновить данные рабочего пространства.
-   **Аутентификация:** Требуется (только владелец).
-   **Требуемые права:** `workspaces.edit`
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса (`multipart/form-data`):**
    -   **Примечание:** Для обновления с файлом необходимо отправлять `POST`-запрос, добавив в тело поле `_method` со значением `PUT` или `PATCH`.
    -   `name`: (string, optional) Новое название.
    -   `description`: (string, optional) Новое описание.
    -   `logo`: (file, optional) Новый файл логотипа. Старый будет удален.

#### `GET /workspaces/{workspace}/users`
Получить список всех пользователей в рабочем пространстве.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `users.view`
-   **Параметры URL:** `workspace` (ID).
-   **Ответ:** Возвращает массив объектов пользователей. Каждый объект содержит поле `has_account` (`true`, если у пользователя заполнено имя, иначе `false`).

#### `POST /workspaces/{workspace}/users/invite`
Пригласить пользователей в рабочее пространство. Существующие в системе пользователи будут добавлены, для несуществующих будут созданы новые учетные записи.
-   **Аутентификация:** Требуется (только владелец).
-   **Требуемые права:** `users.invite`
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса:**
    ```json
    {
        "emails_text": "user1@example.com, already-member@example.com; new-user@example.com"
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "added": ["user1@example.com"],
        "invited": ["new-user@example.com"],
        "failed": [
            { "email": "already-member@example.com", "reason": "Пользователь уже является участником этого рабочего пространства." }
        ]
    }
    ```

#### `GET /workspaces/{workspace}/users/{user}`
Получить информацию о конкретном участнике рабочего пространства, включая его роль и отдел.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `users.view`
-   **Параметры URL:** `workspace` (ID), `user` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "id": 2,
        "name": "Jane Doe",
        "email": "jane.doe@example.com",
        "has_account": true,
        "role_id": 1,
        "department_id": 1
    }
    ```

#### `PUT /workspaces/{workspace}/users/{user}`
Обновить роль и/или отдел пользователя в рабочем пространстве.
-   **Аутентификация:** Требуется (только владелец).
-   **Требуемые права:** `users.edit`
-   **Параметры URL:** `workspace` (ID), `user` (ID).
-   **Тело запроса:**
    ```json
    {
        "role_id": 2,
        "department_id": 5
    }
    ```

#### `DELETE /workspaces/{workspace}/users/{user}`
Удалить пользователя из рабочего пространства.
-   **Аутентификация:** Требуется (только владелец).
-   **Требуемые права:** `users.delete`
-   **Параметры URL:** `workspace` (ID), `user` (ID).

---

### Клиенты (Clients)

#### `GET /workspaces/{workspace}/clients`
Получить список клиентов для указанного рабочего пространства.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.view`
-   **Параметры URL:** `workspace` (ID).

#### `POST /workspaces/{workspace}/clients`
Создать нового клиента в рабочем пространстве.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.store`
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса:**
    ```json
    {
        "organization_type": "ООО",
        "short_name": "ООО \"Рога и копыта\"",
        "name": "Общество с ограниченной ответственностью \"Рога и копыта\"",
        "inn": "1234567890",
        "kpp": "123456789",
        "ogrn": "1234567890123",
        "address": "г. Москва, ул. Центральная, д. 1, оф. 101",
        "postal_address": "123456, г. Москва, а/я 10",
        "website": "https://roga-i-kopyta.ru",
        "phone": "+74951234567",
        "email": "contact@roga-i-kopyta.ru",
        "checking_account": "40702810123450000001",
        "bank_name": "ПАО СБЕРБАНК",
        "correspondent_account": "30101810400000000225",
        "bik": "044525225",
        "general_director": "Петров Петр Петрович"
    }
    ```

#### `GET /workspaces/{workspace}/clients/{client}`
Получить информацию о конкретном клиенте.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.view`
-   **Параметры URL:** `workspace` (ID), `client` (ID).

#### `PUT|PATCH /workspaces/{workspace}/clients/{client}`
Обновить информацию о клиенте.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.edit`
-   **Параметры URL:** `workspace` (ID), `client` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Обновленное ООО \"Рога и копыта\"",
        "website": "https://new-roga.ru"
    }
    ```

#### `DELETE /workspaces/{workspace}/clients/{client}`
Удалить клиента.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.delete`
-   **Параметры URL:** `workspace` (ID), `client` (ID).

---

### Контакты (Contacts)

Эндпоинты для управления контактными лицами клиентов.

#### `GET /workspaces/{workspace}/clients/{client}/contacts`
Получить список контактов для указанного клиента.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `clients.view`
-   **Параметры URL:** `workspace` (ID), `client` (ID).

#### `POST /workspaces/{workspace}/clients/{client}/contacts`
Создать новое контактное лицо для клиента.
-   **Аутентификация:** Требуется (владелец или редактор рабочего пространства).
-   **Требуемые права:** `clients.contacts.edit`
-   **Параметры URL:** `workspace` (ID), `client` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Иван Иванов",
        "position": "Менеджер по закупкам",
        "email": "i.ivanov@example.com",
        "phone": "+79261234567",
        "is_default": true
    }
    ```
    *Поля `position` и `email` необязательны. Поле `is_default` (булево) указывает, является ли контакт основным. При установке `true` для одного контакта, у всех остальных контактов этого клиента `is_default` автоматически станет `false`.*

#### `GET /workspaces/{workspace}/contacts/{contact}`
Получить информацию о конкретном контакте.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `clients.view`
-   **Параметры URL:** `workspace` (ID), `contact` (ID).

#### `PUT|PATCH /workspaces/{workspace}/contacts/{contact}`
Обновить информацию о контакте.
-   **Аутентификация:** Требуется (владелец или редактор рабочего пространства).
-   **Требуемые права:** `clients.contacts.edit`
-   **Параметры URL:** `workspace` (ID), `contact` (ID).
-   **Тело запроса:** (любое из полей, доступных при создании)

#### `DELETE /workspaces/{workspace}/contacts/{contact}`
Удалить контакт.
-   **Аутентификация:** Требуется (владелец или редактор рабочего пространства).
-   **Требуемые права:** `clients.contacts.delete`
-   **Параметры URL:** `workspace` (ID), `contact` (ID).

---

### Файлы клиента (Client Files)

Эндпоинты для управления файлами, прикрепленными к клиенту.

#### `GET /workspaces/{workspace}/clients/{client}/files`
Получить список файлов для указанного клиента.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.view`
-   **Параметры URL:** `workspace` (ID), `client` (ID).

#### `POST /workspaces/{workspace}/clients/{client}/files`
Загрузить новый файл для клиента. Запрос должен быть `multipart/form-data`.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.files.upload`
-   **Параметры URL:** `workspace` (ID), `client` (ID).
-   **Тело запроса (form-data):**
    -   `file`: (file) Загружаемый файл.
    -   `document_type`: (string) Тип документа ('contract', 'statement', 'invoice', 'other').
    -   `description`: (string, optional) Описание файла.
-   **Успешный ответ (Код `201 CREATED`):**
    ```json
    {
        "id": 1,
        "client_id": 1,
        "name": "contract.pdf",
        "path": "clients/1/files/xyz.pdf",
        "file_type": "pdf",
        "document_type": "contract",
        "description": "Договор на оказание услуг",
        "created_at": "2024-08-05T10:00:00.000000Z",
        "updated_at": "2024-08-05T10:00:00.000000Z"
    }
    ```

#### `GET /workspaces/{workspace}/files/{clientFile}`
Получить информацию о конкретном файле.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.view`
-   **Параметры URL:** `workspace` (ID), `clientFile` (ID).

#### `PUT|PATCH /workspaces/{workspace}/files/{clientFile}`
Обновить информацию о файле (тип документа, описание).
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.files.upload`
-   **Параметры URL:** `workspace` (ID), `clientFile` (ID).
-   **Тело запроса (JSON):**
    ```json
    {
        "document_type": "statement",
        "description": "Подписанный акт выполненных работ"
    }
    ```

#### `DELETE /workspaces/{workspace}/files/{clientFile}`
Удалить файл.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.files.delete`
-   **Параметры URL:** `workspace` (ID), `clientFile` (ID).

### Проекты (Projects)

#### `GET /workspaces/{workspace}/clients/{client}/projects`
Получить список проектов для указанного клиента.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.view`
-   **Параметры URL:** `workspace` (ID), `client` (ID).

#### `POST /workspaces/{workspace}/clients/{client}/projects`
Создать новый проект для клиента.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.projects.edit`
-   **Параметры URL:** `workspace` (ID), `client` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Project X",
        "status": "planning",
        "description": "Description for Project X",
        "notes": "Some notes about the project",
        "city": "Moscow",
        "ogrn": "1234567890123"
    }
    ```
    *Поле `status` является необязательным. Доступные значения: `planning`, `in_progress`, `completed`, `on_hold`.*

#### `GET /workspaces/{workspace}/projects/{project}`
Получить информацию о конкретном проекте.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.view`
-   **Параметры URL:** `workspace` (ID), `project` (ID).

#### `PUT|PATCH /workspaces/{workspace}/projects/{project}`
Обновить информацию о проекте.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.projects.edit`
-   **Параметры URL:** `workspace` (ID), `project` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Updated Project X",
        "city": "Saint Petersburg"
    }
    ```

#### `DELETE /workspaces/{workspace}/projects/{project}`
Удалить проект.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `clients.projects.delete`
-   **Параметры URL:** `workspace` (ID), `project` (ID).

---

### Задачи (Tasks)

#### `GET /workspaces/{workspace}/tasks`
Получить список задач для рабочего пространства. Владелец видит все задачи, участники - только те, где они исполнители или соисполнители.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** 
  - `tasks.view` - просмотр своих задач
  - `tasks.view.all` - просмотр всех задач
-   **Параметры URL:** `workspace` (ID).
- **Права:** 
  - `tasks.view` - просмотр своих задач
  - `tasks.view.all` - просмотр всех задач

#### `POST /workspaces/{workspace}/tasks`
Создать новую задачу в рабочем пространстве.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `tasks.create`
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса:** Запрос должен быть `multipart/form-data`.
    -   `title`: (string, required) Название задачи.
    -   `description`: (string, optional) Описание.
    -   `status`: (string, optional) Статус ('new', 'in_progress', 'pending_review', 'completed', 'archived').
    -   `priority`: (string, optional) Приоритет ('normal', 'high', 'critical').
    -   `deadline`: (date, optional) Срок выполнения (e.g., '2024-12-31T23:59:59').
    -   `performer_id`: (integer, required) ID исполнителя.
    -   `taskable_type`: (string, required) Тип связанного объекта ('project' или 'lead').
    -   `taskable_id`: (integer, required) ID связанного объекта.
    -   `images[]`: (file, optional) Массив файлов изображений.
    -   `users[0][user_id]`: (integer, optional) ID участника.
    -   `users[0][can_edit]`: (boolean, optional) Право на редактирование для участника.
    -   `users[1][user_id]`: ...
    - **Права:** `tasks.create`

    *Основной исполнитель (`performer_id`) автоматически добавляется в список участников с правом на редактирование.*

-   **Успешный ответ (Код `201 CREATED`):**
    *Возвращает созданный объект задачи (см. пример ответа для `GET /tasks/{task}`).*

#### `GET /workspaces/{workspace}/tasks/{task}`
Получить информацию о конкретной задаче.
-   **Аутентификация:** Требуется (участник задачи или владелец рабочего пространства).
-   **Параметры URL:** `workspace` (ID), `task` (ID).
-   **Успешный ответ (Код `200 OK`):**
    *Возвращает объект задачи со связанными данными: `performer`, `users`, `images`, `project`. В объектах пользователей (`performer`, `users`) также присутствует флаг `has_account`.*
    ```json
    {
        "id": 1,
        "workspace_id": 1,
        "taskable_type": "project",
        "taskable_id": 5,
        "performer_id": 2,
        "title": "Design new landing page",
        "description": "Detailed description of the task.",
        "status": "new",
        "priority": "high",
        "deadline": "2024-12-31T23:59:59.000000Z",
        "created_at": "2024-08-06T10:00:00.000000Z",
        "updated_at": "2024-08-06T10:00:00.000000Z",
        "performer": {
            "id": 2,
            "name": "Jane Doe",
            "email": "jane.doe@example.com",
            "has_account": true
        },
        "users": [],
        "images": [],
        "taskable": {
            "id": 5,
            "name": "E-commerce Platform"
        }
    }
    ```
- **Требуемые права:** 
    - `tasks.view` - если пользователь является ответственным или исполнителем
    - `tasks.view.all` - для просмотра любой задачи

#### `PUT|PATCH /workspaces/{workspace}/tasks/{task}`
Обновить информацию о задаче. Для обновления списка участников необходимо передать полный массив `users`.
-   **Аутентификация:** Требуется (владелец рабочего пространства или участник задачи с правом на редактирование).
-   **Параметры URL:** `workspace` (ID), `task` (ID).
-   **Тело запроса:** Запрос должен быть `multipart/form-data`.
    -   **Важное примечание:** Поскольку HTTP-методы `PUT` и `PATCH` не поддерживают `multipart/form-data` нативно, для обновления задачи с файлами необходимо отправлять `POST`-запрос, добавив в тело поле `_method` со значением `PUT` или `PATCH`.

    -   `title`: (string, optional)
    -   `description`: (string, optional)
    -   ... (любые другие поля из формы создания)
    -   `new_images[]`: (file, optional) Массив новых файлов для добавления.
    -   `images_to_delete[]`: (integer, optional) Массив ID существующих изображений для удаления.
    -   `users[0][user_id]`: (integer, optional)
    -   `users[0][can_edit]`: (boolean, optional)
-   **Требуемые права:** 
    - `tasks.edit` - для своих задач
    - `tasks.edit.all` - для любых задач
    - `tasks.status.all` - для изменения статуса
    - `tasks.assign.responsible` - для изменения ответственного
    - `tasks.assign.executors` - для изменения списка исполнителей

    *При передаче массива `users` происходит полная синхронизация участников. Исполнитель (`performer_id`) всегда будет иметь право на редактирование (`can_edit: true`).*
-   **Успешный ответ (Код `200 OK`):**
    *Возвращает обновленный объект задачи (см. пример ответа для `GET /tasks/{task}`).*

    
#### `POST /workspaces/{workspace}/tasks/{task}/images`
Загрузить один или несколько файлов (изображений) к задаче.
-   **Аутентификация:** Требуется (участник задачи с правом на редактирование).
-   **Требуемые права:** `tasks.edit` (для своих задач) или `tasks.edit.all` (для любых задач)
-   **Параметры URL:** `workspace` (ID), `task` (ID).
-   **Тело запроса (multipart/form-data):**
    -   `files[]`: (file, required) Массив файлов для загрузки (одно или несколько изображений).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "files": [
            {
                "id": 1,
                "name": "test.jpg",
                "url": "https://sellico.ru/storage/tasks/images/test.jpg",
                "created_at": "2025-11-26T12:00:00+03:00"
            }
        ]
    }
    ```
-   **Ошибки:**
    - `401 Unauthorized` — требуется аутентификация
    - `403 Forbidden` — недостаточно прав
    - `422 Unprocessable Entity` — ошибка валидации (например, не переданы файлы или неверный формат)

### Подзадачи (Subtasks)

#### `GET /api/workspaces/{workspace}/tasks/{task}/subtasks`
Получить список подзадач для задачи.
-   **Аутентификация:** Требуется (участник задачи).
-   **Требуемые права:** `roadmap.view`
-   **Параметры URL:**
    - `workspace` (ID рабочего пространства)
    - `task` (ID задачи).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "task_id": 1,
        "subtasks": [
            {
                "id": 1,
                "task_id": 1,
                "title": "Написать сценарий",
                "is_completed": false,
                "order_index": 0,
                "created_at": "2024-08-06T10:00:00.000000Z",
                "updated_at": "2024-08-06T10:00:00.000000Z"
            },
            {
                "id": 2,
                "task_id": 1,
                "title": "Согласовать с заказчиком",
                "is_completed": true,
                "order_index": 1,
                "created_at": "2024-08-06T10:00:00.000000Z",
                "updated_at": "2024-08-06T10:00:00.000000Z"
            }
        ],
        "ai_metadata": {
            "is_generated_by_ai": true,
            "generated_at": "2024-08-06T10:00:00.000000Z"
        }
    }
    ```

#### `POST /api/workspaces/{workspace}/tasks/{task}/subtasks`
Создать новую подзадачу.
-   **Аутентификация:** Требуется (участник рабочего пространства с правами на редактирование).
-   **Требуемые права:** `roadmap.edit`
-   **Параметры URL:** `workspace` (ID), `task` (ID).
-   **Тело запроса (JSON):**
    -   `title`: (string, required) Название подзадачи, максимум 500 символов.
    -   `is_completed`: (boolean, optional) Статус выполнения подзадачи. По умолчанию `false`.
    -   `order_index`: (integer, optional) Порядковый номер подзадачи. По умолчанию 0.

**Пример запроса:**
```json
{
    "title": "Новая подзадача",
    "is_completed": false,
    "order_index": 0
}
```

**Пример успешного ответа (Код `201 Created`):**
```json
{
    "id": 1,
    "title": "Новая подзадача",
    "is_completed": false,
    "order_index": 0,
    "task_id": 1,
    "created_at": "2025-03-20T10:00:00.000000Z",
    "updated_at": "2025-03-20T10:00:00.000000Z"
}
```

**Ошибки:**
-   `401 Unauthorized` - Требуется аутентификация
-   `403 Forbidden` - Недостаточно прав для создания подзадачи
-   `422 Unprocessable Entity` - Ошибка валидации (например, не указано название подзадачи)

#### `POST /api/workspaces/{workspace}/tasks/{task}/subtasks/generate`
Генерация подзадач с использованием ИИ.
-   **Аутентификация:** Требуется (участник рабочего пространства с правами на редактирование).
-   **Требуемые права:** `roadmap.edit`
-   **Параметры URL:** `workspace` (ID), `task` (ID).
-   **Параметры запроса (query):**
    -   `regenerate`: (boolean, optional) Перегенерировать существующие подзадачи. По умолчанию `false`.

**Пример успешного ответа (Код `201 Created`):**
```json
{
    "message": "Подзадачи успешно сгенерированы",
    "task_id": 1,
    "subtasks_count": 3,
    "subtasks": [
        {
            "id": 1,
            "title": "Проанализировать требования",
            "is_completed": false,
            "order_index": 0,
            "task_id": 1
        },
        {
            "id": 2,
            "title": "Создать макет",
            "is_completed": false,
            "order_index": 1,
            "task_id": 1
        },
        {
            "id": 3,
            "title": "Написать тесты",
            "is_completed": false,
            "order_index": 2,
            "task_id": 1
        }
    ]
}
``` 
- если подзадачи уже существуют и не указан `regenerate=true`
- `500 Internal Server Error` - при ошибке генерации

#### `POST /api/workspaces/{workspace}/projects/{project}/tasks/generate-subtasks`
Массовая генерация подзадач для нескольких задач проекта.
-   **Аутентификация:** Требуется (участник проекта с правами на редактирование).
-   **Требуемые права:** `roadmap.edit`
-   **Параметры URL:**
    - `workspace` (ID рабочего пространства)
    - `project` (ID проекта).
-   **Тело запроса (JSON):**
    ```json
    {
        "task_ids": [1, 2, 3],
        "regenerate_existing": false
    }
    ```
    - `task_ids` (array of integers, optional) - ID задач для генерации. Если не указано, генерируются для всех задач проекта.
    - `regenerate_existing` (boolean, optional, default: false) - Перегенерировать существующие подзадачи.
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Массовая генерация завершена",
        "project_id": 1,
        "processed_tasks": 3,
        "total_subtasks": 12,
        "results": [
            {"task_id": 1, "status": "generated", "subtasks_count": 4},
            {"task_id": 2, "status": "skipped", "message": "Подзадачи уже существуют"},
            {"task_id": 3, "status": "generated", "subtasks_count": 5}
        ]
    }
    ```

#### `PATCH /api/workspaces/{workspace}/subtasks/{subtask}`
Обновить подзадачу.
-   **Аутентификация:** Требуется (участник задачи).
-   **Требуемые права:** `roadmap.edit`
-   **Параметры URL:**
    - `workspace` (ID рабочего пространства)
    - `subtask` (ID подзадачи).
-   **Тело запроса (JSON):**
    ```json
    {
        "title": "Обновленный заголовок",
        "is_completed": true
    }
    ```
    - `title` (string, optional) - Новый заголовок подзадачи.
    - `is_completed` (boolean, optional) - Статус выполнения.
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "id": 1,
        "task_id": 1,
        "title": "Обновленный заголовок",
        "is_completed": true,
        "order_index": 0,
        "created_at": "2024-08-06T10:00:00.000000Z",
        "updated_at": "2024-08-07T15:30:00.000000Z"
    }
    ```

#### `DELETE /api/workspaces/{workspace}/subtasks/{subtask}`
Удалить подзадачу.
-   **Аутентификация:** Требуется (участник задачи с правами на редактирование).
-   **Требуемые права:** `roadmap.edit`
-   **Параметры URL:**
    - `workspace` (ID рабочего пространства)
    - `subtask` (ID подзадачи).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Подзадача удалена"
    }
    ```

#### `DELETE /workspaces/{workspace}/tasks/{task}`
Удалить задачу.
-   **Аутентификация:** Требуется (только владелец рабочего пространства).
-   **Параметры URL:** `workspace` (ID), `task` (ID).
    *Удаление задачи также приведет к удалению всех связанных с ней изображений с сервера.*
- **Требуемые права:** `tasks.delete`

### Статусы задач
- `new` - Новая задача
- `in_progress` - В работе
- `pending_review` - На проверке
- `completed` - Завершена
- `archived` - В архиве

### Приоритеты задач
- `low` - Низкий
- `medium` - Средний
- `high` - Высокий
- `critical` - Критический

#### Дополнительные атрибуты задач
- `was_overdue` (boolean): флаг того, что задача когда-либо была просрочена. Становится `true`, если на момент ежедневной проверки `deadline < now`, а статус не `completed` и не `archived`.

#### Ежедневная фоновая обработка задач
- Job: `app/Jobs/ProcessTasksDaily.php`, планируется в `app/Console/Kernel.php` ежедневно в 01:00.
- Действия:
  1) Отмечает просроченные задачи — выставляет `was_overdue = true`.
  2) Архивирует завершённые задачи — переводит задачи со статусом `completed` в `archived`, если `updated_at <= now() - 7 дней`.
- Прод: настройте cron для `php artisan schedule:run` каждую минуту и запустите воркер очереди (`php artisan queue:work`).

---

### Комментарии к задачам (Comments)

#### `GET /tasks/{task}/comments`
Получить список комментариев для задачи.
-   **Аутентификация:** Требуется (участник задачи или владелец рабочего пространства).
-   **Требуемые права:** —
-   **Параметры URL:** `task` (ID).

#### `POST /tasks/{task}/comments`
Создать новый комментарий к задаче.
-   **Аутентификация:** Требуется (участник задачи или владелец рабочего пространства).
-   **Требуемые права:** —
-   **Параметры URL:** `task` (ID).
-   **Тело запроса (`multipart/form-data`):**
    -   `body`: (string, required) Текст комментария.
    -   `images[]`: (file, optional) Массив файлов изображений.

#### `PUT|PATCH /comments/{comment}`
Обновить комментарий.
-   **Аутентификация:** Требуется (только автор комментария).
-   **Требуемые права:** —
-   **Параметры URL:** `comment` (ID).
-   **Тело запроса (`multipart/form-data`):**
    -   **Важное примечание:** Поскольку HTTP-методы `PUT` и `PATCH` не поддерживают `multipart/form-data` нативно, для обновления комментария с файлами необходимо отправлять `POST`-запрос, добавив в тело поле `_method` со значением `PUT` или `PATCH`.

    -   `body`: (string, required) Обновленный текст комментария.
    -   `new_images[]`: (file, optional) Массив новых файлов для добавления.
    -   `images_to_delete[]`: (integer, optional) Массив ID существующих изображений для удаления.

#### `DELETE /comments/{comment}`
Удалить комментарий.
-   **Аутентификация:** Требуется (автор комментария или владелец рабочего пространства).
-   **Требуемые права:** —
-   **Параметры URL:** `comment` (ID).
    *Удаление комментария также приведет к удалению всех связанных с ним изображений с сервера.*

---

### Отделы (Departments)

#### `GET /workspaces/{workspace}/departments`
Получить список отделов для указанного рабочего пространства.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `departments.view`
-   **Параметры URL:** `workspace` (ID).

#### `POST /workspaces/{workspace}/departments`
Создать новый отдел в рабочем пространстве.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `departments.edit`
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Sales Department",
        "parent_id": null
    }
    ```

#### `GET /workspaces/{workspace}/departments/{department}`
Получить информацию о конкретном отделе.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `departments.view`
-   **Параметры URL:** `workspace` (ID), `department` (ID).

#### `PUT|PATCH /workspaces/{workspace}/departments/{department}`
Обновить информацию об отделе.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `departments.edit`
-   **Параметры URL:** `workspace` (ID), `department` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Marketing Department",
        "parent_id": 1
    }
    ```

#### `DELETE /workspaces/{workspace}/departments/{department}`
Удалить отдел.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `departments.delete`
-   **Параметры URL:** `workspace` (ID), `department` (ID).

---

### Интеграции (Integrations)

Эндпоинты для управления интеграциями с внешними сервисами (OZON, WildBerries, Yandex.Market).

#### `GET /workspaces/{workspace}/integrations`
Получить список интеграций для указанного рабочего пространства.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `integrations.view`
-   **Параметры URL:** `workspace` (ID).

#### `POST /workspaces/{workspace}/integrations`
Создать новую интеграцию. При создании происходит валидация учетных данных (API Key, Client ID) через API соответствующего сервиса.
-   **Аутентификация:** Требуется (только владелец рабочего пространства).
-   **Требуемые права:** `integrations.edit`
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Мой магазин OZON",
        "type": "OZON",
        "api_key": "xxxx-xxxx-xxxx-xxxx",
        "client_id": "123456",
        "description": "Основная интеграция для OZON",
        "performance_api_key": "your-performance-api-key"
    }
    ```
    *Доступные значения для `type`: `OZON`, `WildBerries`, `YandexMarket`.*

#### `GET /workspaces/{workspace}/integrations/{integration}`
Получить информацию о конкретной интеграции.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `integrations.view`
-   **Параметры URL:** `workspace` (ID), `integration` (ID).
-   **Успешный ответ (Код `200 OK`):**
    *Поле `api_key` не возвращается в ответе из соображений безопасности.*
    ```json
    {
        "id": 1,
        "workspace_id": 1,
        "name": "Мой магазин OZON",
        "type": "OZON",
        "client_id": "123456",
        "description": "Основная интеграция для OZON",
        "performance_api_key": "your-performance-api-key",
        "created_at": "2024-08-08T10:00:00.000000Z",
        "updated_at": "2024-08-08T10:00:00.000000Z"
    }
    ```

#### `GET /workspaces/{workspace}/integrations/{integration}/category`
Получить категории товаров из внешнего сервиса для указанной интеграции. Возвращает массив категорий.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `integrations.view`
-   **Параметры URL:** `workspace` (ID), `integration` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    [
        "Электроника",
        "Одежда",
        "Бытовая техника"
    ]
    ```
-   **Ошибки:**
    - `404 Not Found` - Интеграция не найдена или у пользователя нет к ней доступа
    - `422 Unprocessable Entity` - Ошибка при запросе к внешнему сервису

#### `PUT|PATCH /workspaces/{workspace}/integrations/{integration}`
Обновить информацию об интеграции. При обновлении `api_key` или `client_id` также будет выполнена проверка их валидности.
-   **Аутентификация:** Требуется (только владелец рабочего пространства).
-   **Требуемые права:** `integrations.edit`
-   **Параметры URL:** `workspace` (ID), `integration` (ID).
-   **Тело запроса:**
    ```json
    {
        "name": "Новое название магазина",
        "description": "Обновленное описание",
        "performance_api_key": "updated-performance-api-key"
    }
    ```

#### `DELETE /workspaces/{workspace}/integrations/{integration}`
Удалить интеграцию.
-   **Аутентификация:** Требуется (только владелец рабочего пространства).
-   **Требуемые права:** `integrations.delete`
-   **Параметры URL:** `workspace` (ID), `integration` (ID).

#### `PATCH /workspaces/{workspace}/integrations/{integration}/add-account`
Уведомить о добавлениие сервисного аккаунта к интеграции.
-   **Аутентификация:** Требуется (только владелец рабочего пространства).
-   **Требуемые права:** `integrations.edit`
-   **Параметры URL:** `workspace` (ID), `integration` (ID).
-   **Успешный ответ (Код `200 OK`):**
 
---

### Шаблоны отзывов (Review Templates)

Шаблоны отзывов позволяют хранить преднастроенные ответы для отзывов, включая текст, применимые рейтинги и статус автоответа. Каждый шаблон принадлежит конкретному рабочему пространству и может быть привязан к нескольким интеграциям (many-to-many).

Структура:
- `title`: (string) Название шаблона.
- `text`: (string) Текст ответа.
- `ratings`: (array<int>) Список рейтингов, к которым применим шаблон (значения от 1 до 5). Может быть пустым.
- `categories`: (array<string>, optional) Массив категорий для классификации шаблонов. Каждая категория - строка. Может быть пустым.
- `auto_reply_enabled`: (boolean) Признак автоответа.
- `work_space_id`: (integer) Принадлежность к рабочему пространству.
- `integration_id`: (integer, optional) ID интеграции, к которой привязан шаблон.
- `status`: (boolean) Статус шаблона (true - активен, false - неактивен).

#### `GET /workspaces/{workspace}/review-templates`
Получить список шаблонов для рабочего пространства.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `review-templates.view`
-   **Параметры URL:** `workspace` (ID).

#### `POST /workspaces/{workspace}/review-templates`
Создать новый шаблон отзыва в рабочем пространстве.
-   **Аутентификация:** Требуется (участник рабочего пространства). Права могут контролироваться политиками.
-   **Требуемые права:** `review-templates.edit`
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса (JSON):**
    ```json
    {
      "title": "Спасибо за покупку",
      "text": "Нам важно ваше мнение",
      "ratings": [4, 5],
      "categories": ["Шубы", "Кастрюли"],
      "auto_reply_enabled": true,
      "integration_id": 1,
      "status": true
    }
    ```
    Примечания:
    - `integration_id` должен принадлежать тому же рабочему пространству (`work_space_id`).

#### `GET /workspaces/{workspace}/review-templates/{reviewTemplate}`
Получить информацию о шаблоне (shallow route).
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `review-templates.view`
-   **Параметры URL:** `workspace` (ID), `reviewTemplate` (ID).

#### `PUT|PATCH /workspaces/{workspace}/review-templates/{reviewTemplate}`
Обновить шаблон. Можно менять любые поля, кроме принадлежности к рабочему пространству.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `review-templates.edit`
-   **Параметры URL:** `workspace` (ID), `reviewTemplate` (ID).
-   **Тело запроса (JSON):** Любые из полей создания, все опционально.

#### `DELETE /workspaces/{workspace}/review-templates/{reviewTemplate}`
Удалить шаблон отзыва.
-   **Аутентификация:** Требуется.
-   **Требуемые права:** `review-templates.delete`
-   **Параметры URL:** `workspace` (ID), `reviewTemplate` (ID).

---

### Координации (Coordinations)

Эндпоинт для получения данных о координациях для рабочего пространства.

#### `GET /workspaces/{workspace}/coordinations`
Получить данные о координациях для указанного рабочего пространства за определенный период. Данные кешируются; при первом запросе они получаются от внешнего сервиса-коллектора, при последующих — из кеша.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `coordinations.view`
-   **Параметры URL:** `workspace` (ID).
-   **Параметры запроса (query):**
    -   `start_date`: (date, required) Дата начала периода в формате `Y-m-d`.
    -   `end_date`: (date, required) Дата окончания периода в формате `Y-m-d`.
-   **Пример запроса:**
    ```
    GET /api/workspaces/1/coordinations?start_date=2024-08-01&end_date=2024-08-31
    ```
-   **Успешный ответ (Код `200 OK`):**
    *Возвращает массив данных, полученных от сервиса-коллектора. Структура массива зависит от ответа коллектора.*
    ```json
   {
        "message": "Данные успешно получены.",
        "data": [
            {
                "integration_id": "123",
                "integration_name": "OZON",
                "items": [
                    {
                        "id": 2,
                        "client_id": 123,
                        "date": "2023-10-27",
                        "sales": 12,
                        "revenue": "18000.00",
                        "average_check": "1500.00",
                        "total_impressions": 50000,
                        "ad_cost_share": "10.50",
                        "impressions": 20000,
                        "clicks": 400,
                        "cart_adds": 50,
                        "orders": 25,
                        "created_at": "2023-10-28T10:00:00.000000Z",
                        "updated_at": "2023-10-28T10:00:00.000000Z"
                    },
                    {
                        "id": 1,
                        "client_id": 123,
                        "date": "2023-10-26",
                        "sales": 10,
                        "revenue": "15000.00",
                        "average_check": "1500.00",
                        "total_impressions": 45000,
                        "ad_cost_share": "11.00",
                        "impressions": 18000,
                        "clicks": 350,
                        "cart_adds": 45,
                        "orders": 20,
                        "created_at": "2023-10-27T10:00:00.000000Z",
                        "updated_at": "2023-10-27T10:00:00.000000Z"
                    }
                ]
            },
            {
                "integration_id": "456",
                "integration_name": "Shop",
                "items": [
                    {
                        "id": 3,
                        "client_id": 456,
                        "date": "2023-10-26",
                        "sales": 8,
                        "revenue": "11000.00",
                        "average_check": "1375.00",
                        "total_impressions": 30000,
                        "ad_cost_share": "9.00",
                        "impressions": 15000,
                        "clicks": 300,
                        "cart_adds": 30,
                        "orders": 15,
                        "created_at": "2023-10-27T10:00:00.000000Z",
                        "updated_at": "2023-10-27T10:00:00.000000Z"
                    }
                ]
            }
        ]
    }
    ```

---

### Отзывы (Reviews)

Модуль для работы с отзывами из внешних сервисов (Яндекс, Google, 2ГИС и другие). Позволяет получать, фильтровать, управлять отзывами и отвечать на них через единый API. Поддерживает пагинацию, фильтрацию по дате и статусу, а также обогащение данных информацией об интеграции.

#### `GET /workspaces/{workspace}/reviews`
Получить список отзывов с возможностью фильтрации и пагинации.

- **Аутентификация:** Требуется (токен доступа)
- **Требуемые права:** `reviews.view`
- **Параметры URL:** `workspace` (ID).
- **Параметры запроса (query):**
  - `integration_ids[]`: (array, required) Массив ID интеграций, для которых запрашиваются отзывы
  - `values[]`: (array, optional) Массив оценок для фильтрации (от 1 до 5). Если не передан, возвращаются отзывы с любыми оценками.
  - `isAnswered`: (boolean, optional) Фильтр по статусу ответа.
    - `1` - получить только отвеченные отзывы
    - `0` - получить только неотвеченные отзывы
    - Если параметр не передан, возвращаются все отзывы
  - `text`: (string, optional) Фильтр по тексту отзыва
  - `page`: (integer, optional) Номер страницы (по умолчанию 1)
  - `limit`: (integer, optional) Количество элементов на странице (10, 25, 50 или 100, по умолчанию 25)

#### `POST /workspaces/{workspace}/reviews/respond`
Отправить ответ на отзыв.

- **Аутентификация:** Требуется (токен доступа)
- **Параметры URL:** `workspace` (ID).
- **Требуемые права:** `reviews.respond`
- **Тело запроса (JSON):**
  ```json
  {
    "integration_id": 123,
    "review_id": "rev_abc123",
    "response_text": "Благодарим за отзыв! Мы рады, что вам понравилось.",
  }
  ```
  
  - `integration_id`: (integer, required) ID интеграции, к которой относится отзыв
  - `review_id`: (string, required) Уникальный идентификатор отзыва в системе-источнике
  - `response_text`: (string, required) Текст ответа на отзыв (макс. 1000 символов)

- **Успешный ответ (Код `200 OK`):**
  ```json
  {
    "message": "Review response submitted successfully",
    "data": {
      "success": true,
      "message": "Response submitted",
      "data": {
        "response_id": "resp_123",
      }
    }
  }
  ```

- **Ошибки:**
  - `400 Bad Request`: Ошибка при обработке запроса на стороне сервиса-коллектора
  - `401 Unauthorized`: Требуется аутентификация
  - `403 Forbidden`: Недостаточно прав
  - `422 Unprocessable Entity`: Ошибки валидации входных данных
  - `500 Internal Server Error`: Ошибка сервера
-   **Пример запроса (получить неотвеченные отзывы с оценками 1 и 2 для интеграций 123 и 456):**
    ```
    GET /api/reviews?integration_ids[]=123&integration_ids[]=456&values[]=1&values[]=2&isAnswered=0
    ```
-   **Успешный ответ (Код `200 OK`):**
    *Структура ответа зависит от данных, полученных от сервиса-коллектора. Контроллер обогащает каждый элемент в массиве `data` полем `integration_name` и удаляет `review_id` из вложенных `items`.*

    *Каждый отзыв может содержать дополнительные поля в объекте `fields` с информацией о заказе и товаре:*
    - `customerName` (string, опционально) - Имя клиента, оставившего отзыв
    - `article` (string, опционально) - Артикул товара
    - `productName` (string, опционально) - Название товара
    - `categoryName` (string, опционально) - Название категории товара
    - `orderId` (string, опционально) - Идентификатор заказа
    ```json
    {
      "message": "Данные успешно получены.",
      "data": [
        {
          "integration_id": 123,
          "integration_name": "Мой магазин OZON",
          "items": [
            {
              "integration_id": 123,
              "review_text": "Есть недочёты",
              "value": 3,
              "is_answered": false,
              "created_at": "2025-09-17T12:10:15",
              "fields": {
                "customerName": "Иван Иванов",
                "article": "ART12345",
                "productName": "Смартфон XYZ",
                "categoryName": "Электроника",
                "orderId": "ORD789012"
              }
            }
          ]
        },
        {
          "integration_id": 456,
          "integration_name": "Мой магазин WB",
          "items": [
            {
              "integration_id": 456,
              "review_text": "Отличное качество",
              "value": 5,
              "is_answered": true,
              "created_at": "2025-09-16T08:05:01",
              "fields": {
                "customerName": "Петр Петров",
                "article": "ART67890",
                "productName": "Наушники Pro",
                "categoryName": "Аксессуары",
                "orderId": "ORD123456"
              }
            }
          ]
        }
      ]
    }
    ```

---
### Разрешения (Permissions)

#### `GET /workspaces/{workspace}/permissions`
Получить список всех доступных разрешений в системе.

- **Аутентификация:** Требуется (участник рабочего пространства).
- **Требуемые права:** —
- **Параметры URL:** `workspace` (ID рабочего пространства).
- **Успешный ответ (Код `200 OK`):**
    ```json
    [
        {
            "id": 1,
            "name": "View clients",
            "slug": "clients.view",
            "created_at": "2025-10-02T10:00:00.000000Z",
            "updated_at": "2025-10-02T10:00:00.000000Z"
        }
    ]
    ```

### Публичные эндпоинты

#### 1. Получить список задач проекта (публичный доступ)
-   **URL:** `/public/project/{projectId}/tasks`
-   **Метод:** `GET`
-   **Аутентификация:** Не требуется.
-   **Параметры URL:** `projectId` (ID проекта).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "projectId": 1,
        "projectName": "Название проекта",
        "clientName": "Имя клиента",
        "tasks": [
            {
                "id": 1,
                "title": "Название задачи",
                "description": "Описание задачи",
                "status": "in_progress",
                "due_date": "2025-12-31",
                "created_at": "2025-11-26T12:00:00+03:00",
                "subtasks": [
                    {
                        "id": 1,
                        "task_id": 1,
                        "title": "Название подзадачи",
                        "order_index": 1,
                        "is_completed": false,
                        "completed_at": null,
                        "generated_by_ai": false,
                        "created_at": "2025-11-26T12:00:00+03:00",
                        "updated_at": "2025-11-26T12:00:00+03:00"
                    }
                ]
            }
        ]
    }
    ```
-   **Ошибка (Код `404 Not Found`):**
    ```json
    {
        "message": "Project not found"
    }
    ```

### Роли (Roles)

Управление ролями в системе. Все запросы требуют авторизации.

#### `GET /workspaces/{workspace}/roles`
Получить список ролей. Возвращает объект с двумя массивами:
- `workspace_roles` - роли текущего рабочего пространства
- `global_roles` - глобальные роли (work_space_id = null)
- **Требуемые права:** `roles.view`

**Пример ответа:**
```json
{
  "workspace_roles": [
    {
      "id": 1,
      "name": "Admin",
      "slug": "admin",
      "work_space_id": 1
    }
  ],
  "global_roles": [
    {
      "id": 2,
      "name": "Super Admin",
      "slug": "super-admin",
      "work_space_id": null
    }
  ]
}

#### `POST /workspaces/{workspace}/roles`
Создать новую роль в рабочем пространстве.
-   **Аутентификация:** Требуется (право редактирования рабочего пространства).
-   **Требуемые права:** `roles.edit`
-   **Параметры URL:** `workspace` (ID).
-   **Тело запроса (JSON):**
    ```json
    {
        "name": "Аналитик",
        "slug": "analyst",
        "description": "Роль для аналитиков",
        "permissions": [1, 2, 5]
    }
    ```
-   **Успешный ответ (Код `201 CREATED`):** Возвращает созданную роль с загруженными правами.

#### `GET /workspaces/{workspace}/roles/{role}`
Получить данные конкретной роли.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Требуемые права:** `roles.view`
-   **Параметры URL:** `workspace` (ID), `role` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "id": 2,
        "work_space_id": 1,
        "name": "Администратор",
        "slug": "admin",
        "description": "Полный доступ",
        "is_system": true,
        "permissions": [
            { "id": 1, "name": "Просмотр клиентов", "slug": "clients.view" },
            { "id": 2, "name": "Редактирование клиентов", "slug": "clients.edit" }
        ]
    }
    ```

#### `PUT|PATCH /workspaces/{workspace}/roles/{role}`
Обновить данные роли (название, описание, slug, список прав, флаг `is_system`). При передаче массива `permissions` происходит синхронизация прав.
-   **Аутентификация:** Требуется (право редактирования рабочего пространства).
-   **Требуемые права:** `roles.edit`
-   **Параметры URL:** `workspace` (ID), `role` (ID).
-   **Тело запроса (JSON):** Любые из полей создания, все опциональны.

#### `DELETE /workspaces/{workspace}/roles/{role}`
Удалить роль (нельзя удалить системную роль — вернёт `422 Unprocessable Entity` с сообщением об ошибке).
-   **Аутентификация:** Требуется (право редактирования рабочего пространства).
-   **Требуемые права:** `roles.delete`
-   **Параметры URL:** `workspace` (ID), `role` (ID).

#### `GET /workspaces/{workspace}/users/{user}/permissions`
Получить список прав пользователя, назначенных через роль рабочего пространства.
-   **Аутентификация:** Требуется (участник рабочего пространства).
-   **Параметры URL:** `workspace` (ID), `user` (ID).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "user_id": 10,
        "role": {
            "id": 3,
            "name": "Менеджер",
            "slug": "manager",
            "permissions": [
                { "id": 1, "name": "Просмотр клиентов", "slug": "clients.view" }
            ]
        },
        "permissions": [
            { "id": 1, "name": "Просмотр клиентов", "slug": "clients.view" }
        ]
    }
    ```
    Если пользователю не назначена роль, возвращаются `role: null` и пустой массив `permissions`.


---

### Roadmap (Дорожная карта)

#### 1. Получить статусы ручного завершения задач
-   **URL:** `/projects/{project}/roadmap/manual-statuses`
-   **Метод:** `GET`
-   **Аутентификация:** Не требуется.
-   **Требуемые права:** —
-   **Параметры URL:** `project` (ID проекта).
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "project_id": 1,
        "manual_completed_ids": [1, 2, 3],
        "count": 3
    }
    ```

#### 2. Отметить задачу как выполненную
-   **URL:** `workspaces/{workspace}/projects/{project}/roadmap/manual-statuses/task/{task}`
-   **Метод:** `POST`
-   **Аутентификация:** Требуется (владелец workspace).
-   **Требуемые права:** `roadmap.edit`
-   **Параметры URL:** 
    - `workspace` (ID рабочего пространства)
    - `project` (ID проекта)
    - `task` (ID задачи)
-   **Успешный ответ (Код `201 CREATED`):**
    ```json
    {
        "message": "Задача отмечена как выполненная",
        "project_id": 1,
        "task_id": 5,
        "marked_by": {
            "id": 1,
            "name": "John Doe"
        },
        "marked_at": "2025-11-12T14:30:00.000000Z"
    }
    ```
-   **Ошибки:**
    - `403 Forbidden` - если у пользователя нет прав
    - `400 Bad Request` - если задача не принадлежит проекту

#### 3. Удалить задачу из выполненных
-   **URL:** `workspaces/{workspace}/projects/{project}/roadmap/manual-statuses/task/{task}`
-   **Метод:** `DELETE`
-   **Аутентификация:** Требуется (владелец workspace).
-   **Требуемые права:** `roadmap.edit`
-   **Параметры URL:**
    - `workspace` (ID рабочего пространства)
    - `project` (ID проекта)
    - `task` (ID задачи)
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Задача возвращена в работу",
        "project_id": 1,
        "task_id": 5
    }
    ```
-   **Ошибки:**
    - `403 Forbidden` - если у пользователя нет прав
    - `404 Not Found` - если задача не найдена в списке выполненных

#### 4. Массовое сохранение статусов задач
-   **URL:** `workspaces/{workspace}/projects/{project}/roadmap/manual-statuses/bulk`
-   **Метод:** `POST`
-   **Аутентификация:** Требуется (владелец workspace).
-   **Требуемые права:** `roadmap.edit`
-   **Параметры URL:**
    - `workspace` (ID рабочего пространства)
    - `project` (ID проекта)
-   **Тело запроса:**
    ```json
    {
        "task_ids": [1, 2, 3, 4, 5]
    }
    ```
-   **Успешный ответ (Код `200 OK`):**
    ```json
    {
        "message": "Статусы сохранены",
        "project_id": 1,
        "saved_count": 5,
        "manual_completed_ids": [1, 2, 3, 4, 5]
    }
    ```
-   **Ошибки:**
    - `403 Forbidden` - если у пользователя нет прав
    - `422 Unprocessable Entity` - если валидация не пройдена

#### 5. Настройки уведомлений (Notification Preferences)
API для управления настройками уведомлений пользователя. Позволяет настраивать каналы уведомлений, типы уведомлений, тихие часы и звуковые настройки.

#### `POST /api/notification-preferences/test-telegram`
Отправить тестовое уведомление в Telegram.

- **Аутентификация:** Требуется (Bearer token)
- **Параметры:** Нет
- **Заголовки:**
  - `Accept: application/json`
  - `Authorization: Bearer {token}`
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "message": "Тестовое уведомление успешно отправлено в Telegram",
      "telegram_id": "123456789"
  }
  ```
- **Ошибки:**
  - `401 Unauthorized` - Требуется аутентификация
  - `400 Bad Request` - Ошибка при отправке уведомления
  - `404 Not Found` - Telegram ID пользователя не найден

#### `GET /api/notification-preferences`
Получить текущие настройки уведомлений пользователя.

- **Аутентификация:** Требуется (Bearer token)
- **Параметры:** Нет
- **Успешный ответ (Код `200 OK`):"**
  ```json
  {
    "channels": {
      "inApp": true,
      "browserPush": false,
      "email": true,
      "telegram": false
    },
    "types": {
      "taskAssigned": {
        "inApp": true,
        "browserPush": true,
        "email": true,
        "telegram": false
      },
      "taskDeadline": {
        "inApp": true,
        "browserPush": true,
        "email": true,
        "telegram": true
      },
      "taskCompleted": {
        "inApp": true,
        "browserPush": false,
        "email": false,
        "telegram": false
      },
      "newApplication": {
        "inApp": true,
        "browserPush": true,
        "email": true,
        "telegram": true
      },
      "leadAssigned": {
        "inApp": true,
        "browserPush": true,
        "email": true,
        "telegram": true
      },
      "newClient": {
        "inApp": true,
        "browserPush": true,
        "email": false,
        "telegram": true
      },
      "integrationError": {
        "inApp": true,
        "browserPush": true,
        "email": true,
        "telegram": false
      }
    },
    "quietHours": {
      "enabled": false,
      "startTime": "22:00:00",
      "endTime": "08:00:00",
      "weekendsOnly": false
    },
    "sound": {
      "enabled": true,
      "volume": 70,
      "tone": "default"
    },
    "telegram": {
      "chatId": null,
      "isConfigured": false
    }
  }

#### PUT /api/notification-preferences
Обновить настройки уведомлений пользователя.

- **Аутентификация:** Требуется (Bearer token)
- **Тело запроса (JSON):**
```json
{
  "channels": {
    "inApp": true,
    "browserPush": false,
    "email": true,
    "telegram": false
  },
  "type_settings": {
    "taskAssigned": {
      "inApp": true,
      "browserPush": true,
      "email": true,
      "telegram": false
    }
  },
  "quiet_hours_enabled": false,
  "quiet_hours_start": "22:00:00",
  "quiet_hours_end": "08:00:00",
  "quiet_hours_weekends_only": false,
  "sound_enabled": true,
  "sound_volume": 70,
  "sound_tone": "default",
  "telegram_chat_id": "123456789",
  "telegram_bot_token": "123456789:AAH123456789",
  "telegram_configured": false
}
```

- **Параметры:**
channels (object, required): Глобальные настройки каналов уведомлений
type_settings (object, required): Настройки для каждого типа уведомлений
quiet_hours_enabled (boolean): Включены ли тихие часы
quiet_hours_start (string, format: HH:MM:SS): Начало тихих часов
quiet_hours_end (string, format: HH:MM:SS): Конец тихих часов
quiet_hours_weekends_only (boolean): Только на выходных
sound_enabled (boolean): Включен ли звук уведомлений
sound_volume (integer, 0-100): Громкость звука
sound_tone (string): Мелодия уведомления
telegram_chat_id (string|null): ID чата в Telegram
telegram_bot_token (string|null): Токен бота в Telegram
telegram_configured (boolean): Настроена ли интеграция с Telegram

- **Успешный ответ (Код 200 OK):** Возвращает обновленные настройки уведомлений
- **Ошибки:**
-   `401 Unauthorized` - Требуется аутентификация
-   `422 Unprocessable Entity` - Ошибка валидации данных
-   `500 Internal Server Error` - Внутренняя ошибка сервера

### Уведомления (Notifications)

#### `GET /api/notifications`
Получить список уведомлений текущего пользователя с пагинацией.
- **Аутентификация:** Требуется (Bearer token)
- **Параметры запроса:**
  - `page` - номер страницы (по умолчанию: 1)
  - `limit` - количество элементов на странице (по умолчанию: 25)
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
    "data": [
      {
        "id": 1,
        "user_id": 1,
        "work_space_id": 1,
        "type": "task_assigned",
        "title": "Новая задача",
        "message": "Вам назначена новая задача",
        "is_read": false,
        "read_at": null,
        "created_at": "2024-08-03T12:00:00.000000Z",
        "updated_at": "2024-08-03T12:00:00.000000Z"
      }
    ],
    "meta": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 1
    }
  }

#### `GET /api/notifications/unread-count`
Получить количество непрочитанных уведомлений.

- **Аутентификация:** Требуется (Bearer token)
- **Успешный ответ (Код `200 OK`):**
```json
{
  "count": 5
}
```

#### `GET /api/notifications/{notification}`
Получить информацию об уведомлении.
- **Аутентификация:** Требуется (Bearer token)
- **Параметры URL:**
  - `notification` (ID уведомления)
- **Успешный ответ (Код `200 OK`):**
```json
{
  "notification": {
    "id": 1,
    "user_id": 1,
    "work_space_id": 1,
    "type": "task_assigned",
    "title": "Новая задача",
    "message": "Вам назначена новая задача",
    "is_read": false,
    "read_at": null,
    "created_at": "2024-08-03T12:00:00.000000Z",
    "updated_at": "2024-08-03T12:00:00.000000Z"
  }
}
```

#### `PATCH /api/notifications/{notification}/read`
Пометить уведомление как прочитанное.

- **Аутентификация:** Требуется (Bearer token)
- **Параметры URL:**
  - `notification` (ID уведомления)
- **Успешный ответ (Код `200 OK`):**
```json
{
  "id": 1,
  "is_read": true,
  "read_at": "2024-08-03T12:05:00.000000Z"
}
```

#### `PATCH /api/notifications/read-all`
Пометить все уведомления как прочитанные.
- **Аутентификация:** Требуется (Bearer token)
- **Успешный ответ (Код `200 OK`):**
```json
{
  "updated_count": 5
}
```

#### `DELETE /api/notifications/{notification}`
Удалить уведомление.
- **Аутентификация:** Требуется (Bearer token)
- **Параметры URL:**
  - `notification` (ID уведомления)
- **Успешный ответ (Код `204 No Content`):**
Пустое тело ответа

#### `DELETE /api/notifications/read-all`
Удалить все прочитанные уведомления.
- **Аутентификация:** Требуется (Bearer token)
- **Успешный ответ (Код `200 OK`):**
```json
{
  "deleted_count": 5
}
```

Возможные ошибки:
401 Unauthorized - Требуется аутентификация
403 Forbidden - Нет доступа к запрошенному ресурсу
404 Not Found - Уведомление не найдено или принадлежит другому пользователю

## Аутентификация через Яндекс OAuth

### `GET /api/auth/yandex/url`
Получить URL для авторизации через Яндекс OAuth.
- **Аутентификация:** Не требуется.
- **Параметры:** Нет.
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "url": "https://oauth.yandex.ru/authorize?response_type=code&client_id=your_client_id&redirect_uri=..."
  }
  ```

### `GET /api/auth/yandex`
Редирект на страницу авторизации Яндекса.
- **Аутентификация:** Не требуется.
- **Параметры:** Нет.
- **Перенаправление:** На страницу авторизации Яндекса.

### `POST /api/auth/yandex/exchange`
Обмен кода на токен (Device Flow).
- **Аутентификация:** Не требуется.
- **Тело запроса (JSON):**
  ```json
  {
      "code": "код_из_письма"
  }
  ```
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "access_token": "ваш_токен_доступа",
      "token_type": "Bearer",
      "expires_in": 31536000,
      "refresh_token": "ваш_токен_обновления",
      "user": {
          "id": 1,
          "name": "Имя Пользователя",
          "email": "user@example.com"
      }
  }
  ```

### `GET /api/auth/yandex/callback`
Callback URL для OAuth-авторизации.
- **Аутентификация:** Не требуется.
- **Параметры URL:** `code` (код авторизации), `state` (опционально).
- **Перенаправление:** На указанный в настройках OAuth redirect_uri с токеном доступа.

## Встречи (Meetings)

### `GET /api/meetings`
Получить список встреч текущего пользователя.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры запроса:**
  - `status` (опционально): Фильтр по статусу (например, 'upcoming', 'past').
- **Успешный ответ (Код `200 OK`):**
  ```json
  [
      {
          "id": 1,
          "title": "Встреча с клиентом",
          "description": "Обсуждение проекта",
          "start_time": "2025-12-10T14:00:00Z",
          "end_time": "2025-12-10T15:00:00Z",
          "status": "scheduled",
          "created_by": 1,
          "created_at": "2025-12-09T10:00:00Z",
          "updated_at": "2025-12-09T10:00:00Z"
      }
  ]
  ```

### `POST /api/meetings`
Создать новую встречу.
- **Аутентификация:** Требуется (Bearer token).
- **Тело запроса (JSON):**
  ```json
  {
      "title": "Встреча с клиентом",
      "description": "Обсуждение проекта",
      "start_time": "2025-12-10T14:00:00Z",
      "end_time": "2025-12-10T15:00:00Z",
      "participants": [2, 3, 4]
  }
  ```
- **Успешный ответ (Код `201 Created`):**
  ```json
  {
      "id": 1,
      "title": "Встреча с клиентом",
      "description": "Обсуждение проекта",
      "start_time": "2025-12-10T14:00:00Z",
      "end_time": "2025-12-10T15:00:00Z",
      "status": "scheduled",
      "created_by": 1,
      "created_at": "2025-12-09T10:00:00Z",
      "updated_at": "2025-12-09T10:00:00Z",
      "participants": [
          {
              "id": 2,
              "name": "Участник 1",
              "email": "participant1@example.com"
          },
          {
              "id": 3,
              "name": "Участник 2",
              "email": "participant2@example.com"
          },
          {
              "id": 4,
              "name": "Участник 3",
              "email": "participant3@example.com"
          }
      ]
  }
  ```

### `GET /api/meetings/{meeting}`
Получить информацию о встрече.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "id": 1,
      "title": "Встреча с клиентом",
      "description": "Обсуждение проекта",
      "start_time": "2025-12-10T14:00:00Z",
      "end_time": "2025-12-10T15:00:00Z",
      "status": "scheduled",
      "created_by": 1,
      "created_at": "2025-12-09T10:00:00Z",
      "updated_at": "2025-12-09T10:00:00Z",
      "participants": [...]
  }
  ```

### `PUT /api/meetings/{meeting}`
Обновить информацию о встрече.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Тело запроса (JSON):**
  ```json
  {
      "title": "Обновленное название встречи",
      "description": "Обновленное описание",
      "start_time": "2025-12-10T15:00:00Z",
      "end_time": "2025-12-10T16:00:00Z"
  }
  ```
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "id": 1,
      "title": "Обновленное название встречи",
      "description": "Обновленное описание",
      "start_time": "2025-12-10T15:00:00Z",
      "end_time": "2025-12-10T16:00:00Z",
      "status": "scheduled",
      "created_by": 1,
      "created_at": "2025-12-09T10:00:00Z",
      "updated_at": "2025-12-09T11:00:00Z"
  }
  ```

### `DELETE /api/meetings/{meeting}`
Удалить встречу.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Успешный ответ (Код `204 No Content`):** Пустое тело ответа.

### `POST /api/meetings/{meeting}/sync`
Синхронизировать встречу с календарем.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "message": "Встреча успешно синхронизирована",
      "sync_status": "success",
      "external_id": "ext_123456789"
  }
  ```

### `POST /api/meetings/{meeting}/cohosts`
Добавить соорганизаторов встречи.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Тело запроса (JSON):**
  ```json
  {
      "user_ids": [5, 6]
  }
  ```
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "message": "Соорганизаторы успешно добавлены",
      "cohosts_added": [5, 6]
  }
  ```

### `DELETE /api/meetings/{meeting}/cohosts`
Удалить соорганизаторов встречи.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Тело запроса (JSON):**
  ```json
  {
      "user_ids": [5, 6]
  }
  ```
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "message": "Соорганизаторы успешно удалены",
      "cohosts_removed": [5, 6]
  }
  ```

### `POST /api/meetings/{meeting}/start`
Начать встречу.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "message": "Встреча начата",
      "meeting_id": 1,
      "status": "in_progress",
      "started_at": "2025-12-10T14:00:00Z"
  }
  ```

### `POST /api/meetings/{meeting}/end`
Завершить встречу.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "message": "Встреча завершена",
      "meeting_id": 1,
      "status": "completed",
      "ended_at": "2025-12-10T15:30:00Z"
  }
  ```

## Видеоконференции Jitsi

### `GET /api/jitsi/meetings`
Получить список видеоконференций.
- **Аутентификация:** Требуется (Bearer token).
- **Успешный ответ (Код `200 OK`):**
  ```json
  [
      {
          "id": 1,
          "room_name": "meeting-room-123",
          "subject": "Обсуждение проекта",
          "start_time": "2025-12-10T14:00:00Z",
          "end_time": "2025-12-10T15:00:00Z",
          "status": "scheduled",
          "created_by": 1,
          "created_at": "2025-12-09T10:00:00Z",
          "updated_at": "2025-12-09T10:00:00Z"
      }
  ]
  ```

### `POST /api/jitsi/meetings`
Создать новую видеоконференцию.
- **Аутентификация:** Требуется (Bearer token).
- **Тело запроса (JSON):**
  ```json
  {
      "subject": "Обсуждение проекта",
      "start_time": "2025-12-10T14:00:00Z",
      "duration": 60,
      "participants": [2, 3, 4]
  }
  ```
- **Успешный ответ (Код `201 Created`):**
  ```json
  {
      "id": 1,
      "room_name": "meeting-room-123",
      "subject": "Обсуждение проекта",
      "start_time": "2025-12-10T14:00:00Z",
      "end_time": "2025-12-10T15:00:00Z",
      "status": "scheduled",
      "join_url": "https://meet.jit.si/meeting-room-123",
      "created_by": 1,
      "created_at": "2025-12-09T10:00:00Z",
      "updated_at": "2025-12-09T10:00:00Z"
  }
  ```

### `GET /api/jitsi/meetings/{meeting}`
Получить информацию о видеоконференции.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "id": 1,
      "room_name": "meeting-room-123",
      "subject": "Обсуждение проекта",
      "start_time": "2025-12-10T14:00:00Z",
      "end_time": "2025-12-10T15:00:00Z",
      "status": "scheduled",
      "join_url": "https://meet.jit.si/meeting-room-123",
      "created_by": 1,
      "created_at": "2025-12-09T10:00:00Z",
      "updated_at": "2025-12-09T10:00:00Z"
  }
  ```

### `POST /api/jitsi/meetings/{meeting}/token`
Получить токен для участника видеоконференции.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Тело запроса (JSON):**
  ```json
  {
      "display_name": "Иван Петров",
      "email": "ivan@example.com"
  }
  ```
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "token": "jwt_token_here",
      "room": "meeting-room-123",
      "display_name": "Иван Петров",
      "email": "ivan@example.com"
  }
  ```

### `GET /api/jitsi/meetings/{meeting}/embed`
Получить HTML-код для встраивания видеоконференции.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "html": "<iframe src='https://meet.jit.si/meeting-room-123#jwt=eyJhbGciOiJ...' width='800' height='600' frameborder='0' allowfullscreen></iframe>",
      "url": "https://meet.jit.si/meeting-room-123"
  }
  ```

### `POST /api/jitsi/meetings/{meeting}/close`
Закрыть видеоконференцию.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "message": "Видеоконференция завершена",
      "meeting_id": 1,
      "status": "ended",
      "ended_at": "2025-12-10T15:30:00Z"
  }
  ```

### `POST /api/jitsi/meetings/{meeting}/sync`
Синхронизировать данные видеоконференции.
- **Аутентификация:** Требуется (Bearer token).
- **Параметры URL:** `meeting` (ID встречи).
- **Успешный ответ (Код `200 OK`):**
  ```json
  {
      "message": "Данные видеоконференции синхронизированы",
      "meeting_id": 1,
      "sync_time": "2025-12-10T15:35:00Z"
  }
  ```

## Коды ошибок

Общий список HTTP-кодов ошибок, которые может возвращать API.

-   `400 Bad Request` - Некорректный запрос. Проверьте синтаксис и параметры.
-   `401 Unauthorized` - Ошибка аутентификации. Необходим валидный токен.
-   `403 Forbidden` - Доступ запрещен. У вас нет прав для выполнения этого действия.
-   `404 Not Found` - Запрашиваемый ресурс не найден.
-   `422 Unprocessable Entity` - Ошибка валидации данных. В теле ответа будут указаны конкретные ошибки.
-   `500 Internal Server Error` - Внутренняя ошибка сервера.
