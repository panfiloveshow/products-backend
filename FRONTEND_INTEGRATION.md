# Инструкция по интеграции Frontend

## Базовая настройка

### Base URL
```
http://localhost:8000/api
```

### Headers
```typescript
const headers = {
  'Content-Type': 'application/json',
  'Accept': 'application/json',
};

// Для авторизованных запросов
const authHeaders = {
  'Content-Type': 'application/json',
  'Accept': 'application/json',
  'Authorization': `Bearer ${accessToken}`,
};
```

---

## 0. Auth API (Sellico Integration)

Авторизация через Sellico API (`sellico.ru/api/login`) для получения API ключей маркетплейсов.

### Endpoints

| Method | Endpoint | Описание |
|--------|----------|----------|
| POST | `/api/auth/login` | Авторизация (email, password) |
| GET | `/api/auth/me` | Профиль пользователя |
| GET | `/api/auth/workspaces` | Список workspaces |
| GET | `/api/auth/workspaces/{id}/integrations` | Интеграции workspace |

### Типы данных

```typescript
// types/auth.ts
interface User {
  id: number;
  name: string;
  email: string;
  is_business: boolean;
  organization: string | null;
}

interface Workspace {
  id: number;
  name: string;
  description: string;
  type: 'pro' | 'free';
  logo_url: string | null;
  created_at: string;
}

interface Integration {
  id: number;
  name: string;           // Название магазина
  type: 'WildBerries' | 'OZON' | 'YandexMarket';
  api_key: string;        // API ключ
  client_id: string;      // Client ID (для Ozon/YM)
  created_at: string;
}

interface IntegrationsResponse {
  success: boolean;
  data: {
    integrations: {
      wildberries?: Integration[];
      ozon?: Integration[];
      yandex_market?: Integration[];
    };
    all: Integration[];   // Все интеграции списком
  };
}

interface LoginResponse {
  success: boolean;
  data: {
    access_token: string;
    user: User;
    workspaces: Workspace[];
  };
}
```

### API сервис

```typescript
// services/authApi.ts
const API_URL = 'http://localhost:8000/api';

export const authApi = {
  // Авторизация
  login: async (email: string, password: string): Promise<LoginResponse> => {
    const response = await fetch(`${API_URL}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    });
    return response.json();
  },

  // Профиль пользователя
  getProfile: async (token: string) => {
    const response = await fetch(`${API_URL}/auth/me`, {
      headers: { 'Authorization': `Bearer ${token}` },
    });
    return response.json();
  },

  // Список workspaces
  getWorkspaces: async (token: string) => {
    const response = await fetch(`${API_URL}/auth/workspaces`, {
      headers: { 'Authorization': `Bearer ${token}` },
    });
    return response.json();
  },

  // Интеграции для workspace
  getIntegrations: async (token: string, workspaceId: number): Promise<IntegrationsResponse> => {
    const response = await fetch(`${API_URL}/auth/workspaces/${workspaceId}/integrations`, {
      headers: { 'Authorization': `Bearer ${token}` },
    });
    return response.json();
  },
};
```

### Пример авторизации и получения интеграций

```tsx
// components/LoginForm.tsx
import { useState } from 'react';
import { authApi } from '../services/authApi';

export function LoginForm() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    
    const result = await authApi.login(email, password);
    
    if (result.success) {
      // Сохраняем токен
      localStorage.setItem('access_token', result.data.access_token);
      
      // Сохраняем workspaces
      const workspaces = result.data.workspaces;
      localStorage.setItem('workspaces', JSON.stringify(workspaces));
      
      // Выбираем первый workspace по умолчанию
      if (workspaces.length > 0) {
        localStorage.setItem('current_workspace_id', String(workspaces[0].id));
      }
      
      // Редирект на дашборд
      window.location.href = '/dashboard';
    } else {
      setError('Неверный email или пароль');
    }
  };

  return (
    <form onSubmit={handleLogin}>
      <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email" />
      <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Пароль" />
      {error && <p className="error">{error}</p>}
      <button type="submit">Войти</button>
    </form>
  );
}
```

### Пример выбора магазина

```tsx
// components/ShopSelector.tsx
import { useState, useEffect } from 'react';
import { authApi } from '../services/authApi';

export function ShopSelector({ onSelect }) {
  const [integrations, setIntegrations] = useState<Integration[]>([]);
  const [loading, setLoading] = useState(true);
  
  const token = localStorage.getItem('access_token');
  const workspaceId = Number(localStorage.getItem('current_workspace_id'));

  useEffect(() => {
    loadIntegrations();
  }, [workspaceId]);

  const loadIntegrations = async () => {
    const result = await authApi.getIntegrations(token, workspaceId);
    if (result.success) {
      setIntegrations(result.data.all);
    }
    setLoading(false);
  };

  if (loading) return <div>Загрузка магазинов...</div>;

  return (
    <div>
      <h3>Выберите магазин</h3>
      
      {/* Wildberries */}
      <div className="marketplace-section">
        <h4>🟣 Wildberries</h4>
        {integrations.filter(i => i.type === 'WildBerries').map(shop => (
          <div key={shop.id} className="shop-card" onClick={() => onSelect(shop)}>
            <span>{shop.name}</span>
            <span className="badge">WB</span>
          </div>
        ))}
      </div>

      {/* Ozon */}
      <div className="marketplace-section">
        <h4>🔵 Ozon</h4>
        {integrations.filter(i => i.type === 'OZON').map(shop => (
          <div key={shop.id} className="shop-card" onClick={() => onSelect(shop)}>
            <span>{shop.name}</span>
            <span className="badge">OZON</span>
          </div>
        ))}
      </div>

      {/* Yandex.Market */}
      <div className="marketplace-section">
        <h4>🟡 Яндекс.Маркет</h4>
        {integrations.filter(i => i.type === 'YandexMarket').map(shop => (
          <div key={shop.id} className="shop-card" onClick={() => onSelect(shop)}>
            <span>{shop.name}</span>
            <span className="badge">YM</span>
          </div>
        ))}
      </div>
    </div>
  );
}
```

### Пример ответа login

```json
{
  "success": true,
  "data": {
    "access_token": "999|MeUcLuy2Udng1mRkV4eR9bQkF8kzkqJm3eVoUzPve8923cd9",
    "user": {
      "id": 2,
      "name": "тест тестович",
      "email": "panfiloveshow@gmail.com"
    },
    "workspaces": [
      { "id": 1, "name": "Sellico development", "type": "pro" },
      { "id": 3, "name": "PlaceSales", "type": "pro" }
    ]
  }
}
```

### Пример ответа integrations

```json
{
  "success": true,
  "data": {
    "integrations": {
      "ozon": [
        { "id": 12, "name": "Бейкер-мейкер", "type": "OZON", "api_key": "xxx", "client_id": "1506904" },
        { "id": 13, "name": "НБК чеки", "type": "OZON", "api_key": "yyy", "client_id": "1925057" }
      ],
      "wildberries": [
        { "id": 24, "name": "Свежее поле", "type": "WildBerries", "api_key": "eyJ..." },
        { "id": 25, "name": "Уютная_мебель", "type": "WildBerries", "api_key": "eyJ..." }
      ],
      "yandex_market": [
        { "id": 21, "name": "Сумки Яндекс", "type": "YandexMarket", "api_key": "ACMA:...", "client_id": "71010557" }
      ]
    },
    "all": [ /* все интеграции списком */ ]
  }
}
```

---

## API Сервисы для Frontend

### 1. Products API

```typescript
// types/product.ts
interface Product {
  id: string;
  sku: string;
  name: string;
  barcode?: string;
  price: number;
  old_price?: number;
  stock: number;
  description?: string;
  images: string[];
  category?: string;
  brand?: string;
  rating?: number;
  reviews_count?: number;
  marketplace: 'wildberries' | 'ozon' | 'yandex';
  marketplace_id: string;
  url?: string;
  wb_data?: object;
  ozon_data?: object;
  yandex_data?: object;
  created_at: string;
  updated_at: string;
}

interface ProductsResponse {
  data: {
    products: Product[];
    total: number;
    page: number;
    limit: number;
    has_more: boolean;
  };
  stats: {
    total: number;
    in_stock: number;
    out_of_stock: number;
    average_price: number;
    total_value: number;
    by_marketplace: Record<string, { count: number; average_price: number }>;
  };
}

// services/productsApi.ts
const API_URL = 'http://localhost:8000/api';

export const productsApi = {
  // Получить список товаров
  getProducts: async (params?: {
    search?: string;
    marketplace?: 'wildberries' | 'ozon' | 'yandex';
    category?: string;
    brand?: string;
    price_from?: number;
    price_to?: number;
    in_stock?: boolean;
    page?: number;
    limit?: number;
    sort?: 'name' | 'price' | 'stock' | 'rating' | 'created_at';
    sort_order?: 'asc' | 'desc';
  }): Promise<ProductsResponse> => {
    const query = new URLSearchParams(params as any).toString();
    const response = await fetch(`${API_URL}/products?${query}`);
    return response.json();
  },

  // Получить товар по ID
  getProduct: async (id: string): Promise<{ data: Product }> => {
    const response = await fetch(`${API_URL}/products/${id}`);
    return response.json();
  },

  // Создать товар
  createProduct: async (product: Partial<Product>): Promise<{ data: Product }> => {
    const response = await fetch(`${API_URL}/products`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(product),
    });
    return response.json();
  },

  // Обновить товар
  updateProduct: async (id: string, product: Partial<Product>): Promise<{ data: Product }> => {
    const response = await fetch(`${API_URL}/products/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(product),
    });
    return response.json();
  },

  // Удалить товар
  deleteProduct: async (id: string): Promise<void> => {
    await fetch(`${API_URL}/products/${id}`, { method: 'DELETE' });
  },

  // Синхронизация с маркетплейсом
  // ВАЖНО: Необходимо передать credentials из интеграции Sellico
  syncProducts: async (
    marketplace: string, 
    credentials: {
      api_key?: string;      // Для WB
      client_id?: string;    // Для Ozon
      token?: string;        // Для Yandex
      campaign_id?: string;  // Для Yandex
    },
    integrationId?: number
  ): Promise<{ data: { sync_id: string; status: string } }> => {
    const response = await fetch(`${API_URL}/products/sync/${marketplace}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...credentials, integration_id: integrationId }),
    });
    return response.json();
  },

  // Статус синхронизации
  getSyncStatus: async (): Promise<{ data: Record<string, any> }> => {
    const response = await fetch(`${API_URL}/products/sync/status`);
    return response.json();
  },
};
```

### 2. Inventory API

```typescript
// types/inventory.ts
interface MarketplaceWarehouse {
  id: string;
  name: string;
  marketplace: 'wildberries' | 'ozon' | 'yandex';
  region?: string;
  quantity: number;
  average_daily_sales?: number;
  days_of_stock?: number;
  recommended_quantity?: number;
  stock_status: 'critical' | 'low' | 'optimal' | 'excess';
}

interface InventoryItem {
  id: string;
  sku: string;
  name: string;
  internal_stock: number;
  image_url?: string;
  cost_price?: number;
  category?: string;
  sales_trend: 'growing' | 'stable' | 'declining';
  sales_28_days: number;
  marketplace_warehouses: MarketplaceWarehouse[];
  financials?: {
    total_value: number;
    frozen_capital: number;
    storage_cost_per_day: number;
    turnover_rate: number;
    days_of_supply: number;
  };
  alerts?: InventoryAlert[];
  last_updated: string;
}

interface InventoryAlert {
  id: string;
  type: 'critical' | 'warning' | 'info';
  warehouse_id: string;
  warehouse_name: string;
  message: string;
  action: 'reorder' | 'redistribute' | 'monitor';
  priority: number;
}

interface DemandForecast {
  sku: string;
  predicted_sales: number;
  change_percent: number;
  confidence: number;
  forecast_period_days: number;
  recommended_order_quantity: number;
  reason: string;
  factors: { name: string; impact: 'positive' | 'negative'; description: string }[];
  warnings?: string[];
}

// services/inventoryApi.ts
export const inventoryApi = {
  // Получить остатки
  getInventory: async (params?: {
    search?: string;
    marketplace?: string;
    low_stock?: boolean;
    out_of_stock?: boolean;
    category?: string;
    sort?: string;
    sort_order?: 'asc' | 'desc';
    page?: number;
    limit?: number;
  }) => {
    const query = new URLSearchParams(params as any).toString();
    const response = await fetch(`${API_URL}/inventory?${query}`);
    return response.json();
  },

  // Остатки по SKU
  getInventoryBySku: async (sku: string) => {
    const response = await fetch(`${API_URL}/inventory/${sku}`);
    return response.json();
  },

  // История остатков
  getHistory: async (sku: string) => {
    const response = await fetch(`${API_URL}/inventory/${sku}/history`);
    return response.json();
  },

  // Прогноз
  getForecast: async (sku: string): Promise<{ data: DemandForecast }> => {
    const response = await fetch(`${API_URL}/inventory/${sku}/forecast`);
    return response.json();
  },

  // Алерты
  getAlerts: async () => {
    const response = await fetch(`${API_URL}/inventory/alerts`);
    return response.json();
  },

  // AI-рекомендации
  getRecommendations: async () => {
    const response = await fetch(`${API_URL}/inventory/recommendations`);
    return response.json();
  },

  // Предложения по перераспределению
  getRedistribution: async () => {
    const response = await fetch(`${API_URL}/inventory/redistribution`);
    return response.json();
  },

  // Общая статистика
  getStats: async () => {
    const response = await fetch(`${API_URL}/inventory/stats`);
    return response.json();
  },

  // Синхронизация
  syncInventory: async (marketplace: string) => {
    const response = await fetch(`${API_URL}/inventory/sync/${marketplace}`, { method: 'POST' });
    return response.json();
  },
};
```

### 3. Shipments API

```typescript
// types/shipment.ts
type ShipmentStatus = 'draft' | 'pending_logistics' | 'approved' | 'sent' | 'in_transit' | 'delivered' | 'rejected';

interface ShipmentItem {
  id: string;
  sku: string;
  product_name: string;
  image_url?: string;
  current_stock: number;
  days_of_stock: number;
  priority: 'critical' | 'medium' | 'low';
  quantity: number;
  cost_price: number;
  total_cost: number;
  ml_recommended: boolean;
  ml_quantity?: number;
  ml_reason?: string;
}

interface Shipment {
  id: string;
  name: string;
  status: ShipmentStatus;
  marketplace: 'wildberries' | 'ozon' | 'yandex';
  shipment_type: 'fbo' | 'fbs' | 'dbs';
  warehouse_name?: string;
  supplier_id: string;
  supplier_name: string;
  items: ShipmentItem[];
  total_items: number;
  total_quantity: number;
  total_cost: number;
  total_volume: number;
  total_weight: number;
  created_at: string;
}

// services/shipmentsApi.ts
export const shipmentsApi = {
  // Список поставок
  getShipments: async (params?: {
    status?: ShipmentStatus[];
    supplier_id?: string;
    marketplace?: string;
    date_from?: string;
    date_to?: string;
    search?: string;
    page?: number;
    limit?: number;
  }) => {
    const query = new URLSearchParams();
    if (params?.status) params.status.forEach(s => query.append('status[]', s));
    if (params?.supplier_id) query.set('supplier_id', params.supplier_id);
    if (params?.marketplace) query.set('marketplace', params.marketplace);
    if (params?.date_from) query.set('date_from', params.date_from);
    if (params?.date_to) query.set('date_to', params.date_to);
    if (params?.search) query.set('search', params.search);
    if (params?.page) query.set('page', String(params.page));
    if (params?.limit) query.set('limit', String(params.limit));
    
    const response = await fetch(`${API_URL}/shipments?${query}`);
    return response.json();
  },

  // Получить поставку
  getShipment: async (id: string) => {
    const response = await fetch(`${API_URL}/shipments/${id}`);
    return response.json();
  },

  // Создать поставку
  createShipment: async (data: {
    name: string;
    marketplace: string;
    shipment_type: string;
    supplier_id: string;
    warehouse_name?: string;
    items?: { sku: string; quantity: number; cost_price?: number }[];
  }) => {
    const response = await fetch(`${API_URL}/shipments`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    return response.json();
  },

  // Добавить товар в поставку
  addItem: async (shipmentId: string, item: {
    sku: string;
    quantity: number;
    cost_price?: number;
    priority?: string;
  }) => {
    const response = await fetch(`${API_URL}/shipments/${shipmentId}/items`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(item),
    });
    return response.json();
  },

  // Обновить количество товара
  updateItem: async (shipmentId: string, itemId: string, data: { quantity: number }) => {
    const response = await fetch(`${API_URL}/shipments/${shipmentId}/items/${itemId}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    return response.json();
  },

  // Удалить товар из поставки
  removeItem: async (shipmentId: string, itemId: string) => {
    await fetch(`${API_URL}/shipments/${shipmentId}/items/${itemId}`, { method: 'DELETE' });
  },

  // Workflow
  submit: async (id: string) => {
    const response = await fetch(`${API_URL}/shipments/${id}/submit`, { method: 'POST' });
    return response.json();
  },

  approve: async (id: string) => {
    const response = await fetch(`${API_URL}/shipments/${id}/approve`, { method: 'POST' });
    return response.json();
  },

  reject: async (id: string, comment?: string) => {
    const response = await fetch(`${API_URL}/shipments/${id}/reject`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ comment }),
    });
    return response.json();
  },

  send: async (id: string) => {
    const response = await fetch(`${API_URL}/shipments/${id}/send`, { method: 'POST' });
    return response.json();
  },

  deliver: async (id: string) => {
    const response = await fetch(`${API_URL}/shipments/${id}/deliver`, { method: 'POST' });
    return response.json();
  },

  // Слоты
  getSlots: async () => {
    const response = await fetch(`${API_URL}/shipments/slots`);
    return response.json();
  },

  bookSlot: async (shipmentId: string, slot: { slot_id: string; date: string; time_from: string; time_to: string }) => {
    const response = await fetch(`${API_URL}/shipments/${shipmentId}/book-slot`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(slot),
    });
    return response.json();
  },

  // ML-рекомендации
  getRecommendations: async () => {
    const response = await fetch(`${API_URL}/shipments/recommendations`);
    return response.json();
  },

  createFromRecommendation: async (recommendationId: string) => {
    const response = await fetch(`${API_URL}/shipments/from-recommendation/${recommendationId}`, { method: 'POST' });
    return response.json();
  },

  // Экспорт
  exportPdf: async (id: string) => {
    const response = await fetch(`${API_URL}/shipments/${id}/export/pdf`);
    return response.json();
  },

  exportCsv: async (id: string) => {
    const response = await fetch(`${API_URL}/shipments/${id}/export/csv`);
    return response.json();
  },

  // Статистика
  getStats: async () => {
    const response = await fetch(`${API_URL}/shipments/stats`);
    return response.json();
  },
};
```

### 4. Unit Economics API

```typescript
// types/unitEconomics.ts
interface UnitEconomics {
  id: number;
  product_id?: number;
  product_name: string;
  sku: string;
  marketplace: 'ozon' | 'wildberries' | 'yandex_market';
  price: number;
  cost_price: number;
  sales_count: number;
  revenue: number;
  total_costs: number;
  gross_profit: number;
  net_profit: number;
  margin_percent: number;
  roi_percent: number;
  period_start: string;
  period_end: string;
  marketplace_data?: object;
}

interface CalculateResult {
  revenue: number;
  total_costs: number;
  gross_profit: number;
  net_profit: number;
  margin_percent: number;
  roi_percent: number;
  // + детализация по маркетплейсу
}

// services/unitEconomicsApi.ts
export const unitEconomicsApi = {
  // Список по всем МП
  getAll: async (params?: {
    search?: string;
    marketplace?: string;
    profitability?: 'all' | 'profitable' | 'unprofitable';
    margin_min?: number;
    margin_max?: number;
    page?: number;
    limit?: number;
  }) => {
    const query = new URLSearchParams(params as any).toString();
    const response = await fetch(`${API_URL}/unit-economics?${query}`);
    return response.json();
  },

  // По маркетплейсу
  getByMarketplace: async (marketplace: string, params?: object) => {
    const query = new URLSearchParams(params as any).toString();
    const response = await fetch(`${API_URL}/unit-economics/${marketplace}?${query}`);
    return response.json();
  },

  // По SKU
  getBySku: async (marketplace: string, sku: string) => {
    const response = await fetch(`${API_URL}/unit-economics/${marketplace}/${sku}`);
    return response.json();
  },

  // Калькулятор
  calculate: async (marketplace: string, data: {
    sku: string;
    price: number;
    cost_price: number;
    sales_count?: number;
    // WB
    wb_commission_percent?: number;
    volume_liters?: number;
    storage_tariff?: number;
    storage_days?: number;
    logistics_cost?: number;
    // Ozon
    fbo_commission_percent?: number;
    fbs_commission_percent?: number;
    last_mile_cost?: number;
    fulfillment_type?: 'FBO' | 'FBS';
    // Yandex
    referral_fee_percent?: number;
    fby_delivery?: number;
  }): Promise<{ data: CalculateResult }> => {
    const response = await fetch(`${API_URL}/unit-economics/calculate/${marketplace}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    return response.json();
  },

  // Сравнение МП
  getComparison: async () => {
    const response = await fetch(`${API_URL}/unit-economics/comparison`);
    return response.json();
  },

  // Статистика
  getStats: async () => {
    const response = await fetch(`${API_URL}/unit-economics/stats`);
    return response.json();
  },

  getStatsByMarketplace: async (marketplace: string) => {
    const response = await fetch(`${API_URL}/unit-economics/stats/${marketplace}`);
    return response.json();
  },

  // Справочники
  getCommissions: async (marketplace: string) => {
    const response = await fetch(`${API_URL}/unit-economics/commissions/${marketplace}`);
    return response.json();
  },

  getTariffs: async (marketplace: string) => {
    const response = await fetch(`${API_URL}/unit-economics/tariffs/${marketplace}`);
    return response.json();
  },
};
```

### 5. Suppliers API

```typescript
// types/supplier.ts
interface Supplier {
  id: string;
  name: string;
  address?: string;
  phone?: string;
  email?: string;
  contact_person?: string;
}

// services/suppliersApi.ts
export const suppliersApi = {
  getAll: async (search?: string) => {
    const query = search ? `?search=${search}` : '';
    const response = await fetch(`${API_URL}/suppliers${query}`);
    return response.json();
  },

  get: async (id: string) => {
    const response = await fetch(`${API_URL}/suppliers/${id}`);
    return response.json();
  },

  create: async (data: Partial<Supplier>) => {
    const response = await fetch(`${API_URL}/suppliers`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    return response.json();
  },

  update: async (id: string, data: Partial<Supplier>) => {
    const response = await fetch(`${API_URL}/suppliers/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    return response.json();
  },

  delete: async (id: string) => {
    await fetch(`${API_URL}/suppliers/${id}`, { method: 'DELETE' });
  },
};
```

---

## React Query интеграция

```typescript
// hooks/useProducts.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { productsApi } from '../services/productsApi';

export const useProducts = (params?: Parameters<typeof productsApi.getProducts>[0]) => {
  return useQuery({
    queryKey: ['products', params],
    queryFn: () => productsApi.getProducts(params),
  });
};

export const useProduct = (id: string) => {
  return useQuery({
    queryKey: ['product', id],
    queryFn: () => productsApi.getProduct(id),
    enabled: !!id,
  });
};

export const useCreateProduct = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: productsApi.createProduct,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
    },
  });
};

export const useSyncProducts = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: productsApi.syncProducts,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
    },
  });
};
```

---

## Axios конфигурация (альтернатива)

```typescript
// lib/api.ts
import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8000/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Интерцептор для обработки ошибок
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 422) {
      // Ошибки валидации
      const errors = error.response.data.errors;
      console.error('Validation errors:', errors);
    }
    return Promise.reject(error);
  }
);

export default api;
```

---

## CORS настройка (Laravel)

Если фронтенд на другом домене, добавьте в `config/cors.php`:

```php
'allowed_origins' => ['http://localhost:3000', 'http://localhost:5173'],
```

---

## Примеры использования в компонентах

### Список товаров
```tsx
import { useProducts } from '../hooks/useProducts';

export function ProductsList() {
  const { data, isLoading, error } = useProducts({ 
    marketplace: 'wildberries',
    in_stock: true,
    limit: 50 
  });

  if (isLoading) return <div>Загрузка...</div>;
  if (error) return <div>Ошибка: {error.message}</div>;

  return (
    <div>
      <div>Всего: {data.stats.total}, В наличии: {data.stats.in_stock}</div>
      {data.data.products.map(product => (
        <div key={product.id}>
          {product.name} - {product.price} ₽
        </div>
      ))}
    </div>
  );
}
```

### Калькулятор юнит-экономики
```tsx
import { useState } from 'react';
import { unitEconomicsApi } from '../services/unitEconomicsApi';

export function UnitEconomicsCalculator() {
  const [result, setResult] = useState(null);

  const calculate = async () => {
    const res = await unitEconomicsApi.calculate('wildberries', {
      sku: 'TEST-001',
      price: 1299,
      cost_price: 450,
      sales_count: 100,
      wb_commission_percent: 15,
      volume_liters: 0.5,
      storage_days: 30,
      logistics_cost: 50,
    });
    setResult(res.data);
  };

  return (
    <div>
      <button onClick={calculate}>Рассчитать</button>
      {result && (
        <div>
          <p>Выручка: {result.revenue} ₽</p>
          <p>Прибыль: {result.net_profit} ₽</p>
          <p>Маржа: {result.margin_percent}%</p>
        </div>
      )}
    </div>
  );
}
```
