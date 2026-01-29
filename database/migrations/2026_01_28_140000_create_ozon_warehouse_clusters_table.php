<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Таблица маппинга складов Ozon к кластерам доставки
     * 
     * Источники данных:
     * - https://dostavka.today/ozon/klasters/
     * - https://sellermoon.ru/faq/ozon/kakoj-sklad-vybrat-dlya-postavok
     */
    public function up(): void
    {
        Schema::create('ozon_warehouse_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse_name', 100)->comment('Название склада (как в Ozon API)');
            $table->string('warehouse_name_normalized', 100)->comment('Нормализованное название для поиска');
            $table->integer('cluster_id')->comment('ID кластера доставки Ozon');
            $table->string('cluster_name', 100)->comment('Название кластера');
            $table->string('region', 100)->nullable()->comment('Регион/город');
            $table->boolean('is_negabarit')->default(false)->comment('Склад для негабаритных товаров');
            $table->boolean('is_jewelry')->default(false)->comment('Склад для ювелирных товаров');
            $table->timestamps();
            
            $table->unique('warehouse_name_normalized');
            $table->index('cluster_id');
        });

        // Заполняем данными о соответствии складов и кластеров
        $this->seedWarehouseClusters();
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_warehouse_clusters');
    }

    /**
     * Заполнение таблицы данными о соответствии складов и кластеров
     * 
     * Данные актуальны на январь 2026 года
     * Источники: dostavka.today, sellermoon.ru
     */
    private function seedWarehouseClusters(): void
    {
        $clusters = [
            // Кластер: Москва, МО и Дальние регионы (ID: 154)
            ['warehouse' => 'ВАТУТИНКИ_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'ГРИВНО_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'ГРИВНО_РФЦ_НЕГАБАРИТ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва', 'negabarit' => true],
            ['warehouse' => 'ДАВЫДОВСКОЕ_РФЦ_НЕГАБАРИТ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва', 'negabarit' => true],
            ['warehouse' => 'ДОМОДЕДОВО_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'ПАВЛО_СЛОБОДСКОЕ_РФЦ_НЕГАБАРИТ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва', 'negabarit' => true],
            ['warehouse' => 'ПЕТРОВСКОЕ_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'СОФЬИНО_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'ХОРУГВИНО_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'ХОРУГВИНО_РФЦ_НЕГАБАРИТ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва', 'negabarit' => true],
            ['warehouse' => 'ЖУКОВСКИЙ_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'НОГИНСК_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'НОГИНСК_РФЦ_НЕГАБАРИТ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва', 'negabarit' => true],
            ['warehouse' => 'ПУШКИНО_1_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'ПУШКИНО_2_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва'],
            ['warehouse' => 'ТВЕРЬ_РФЦ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Тверь'],
            ['warehouse' => 'ТВЕРЬ_ХАБ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Тверь'],
            ['warehouse' => 'РАДУМЛЯ_РФЦ_НЕГАБАРИТ', 'cluster_id' => 154, 'cluster_name' => 'Москва, МО и Дальние регионы', 'region' => 'Москва', 'negabarit' => true],

            // Кластер: Санкт-Петербург и СЗО (ID: 149)
            ['warehouse' => 'САНКТ_ПЕТЕРБУРГ_РФЦ', 'cluster_id' => 149, 'cluster_name' => 'Санкт-Петербург', 'region' => 'Санкт-Петербург'],
            ['warehouse' => 'СПБ_БУГРЫ_РФЦ', 'cluster_id' => 149, 'cluster_name' => 'Санкт-Петербург', 'region' => 'Санкт-Петербург'],
            ['warehouse' => 'СПБ_ВОЛХОНКА_РФЦ_НЕГАБАРИТ', 'cluster_id' => 149, 'cluster_name' => 'Санкт-Петербург', 'region' => 'Санкт-Петербург', 'negabarit' => true],
            ['warehouse' => 'СПБ_КОЛПИНО_РФЦ', 'cluster_id' => 149, 'cluster_name' => 'Санкт-Петербург', 'region' => 'Санкт-Петербург'],
            ['warehouse' => 'СПБ_ШУШАРЫ_РФЦ', 'cluster_id' => 149, 'cluster_name' => 'Санкт-Петербург', 'region' => 'Санкт-Петербург'],
            ['warehouse' => 'СПБ_ШУШАРЫ_РФЦ_НЕГАБАРИТ', 'cluster_id' => 149, 'cluster_name' => 'Санкт-Петербург', 'region' => 'Санкт-Петербург', 'negabarit' => true],
            ['warehouse' => 'СПБ_БУГРЫ_РФЦ_ЮВЕЛИРНЫЙ', 'cluster_id' => 149, 'cluster_name' => 'Санкт-Петербург', 'region' => 'Санкт-Петербург', 'jewelry' => true],

            // Кластер: Казань (ID: 145)
            ['warehouse' => 'КЗН_СТОЛБИЩЕ_РФЦ_НЕГАБАРИТ', 'cluster_id' => 145, 'cluster_name' => 'Казань', 'region' => 'Казань', 'negabarit' => true],
            ['warehouse' => 'КАЗАНЬ_РФЦ_НОВЫЙ', 'cluster_id' => 145, 'cluster_name' => 'Казань', 'region' => 'Казань'],
            ['warehouse' => 'НИЖНИЙ_НОВГОРОД_РФЦ', 'cluster_id' => 145, 'cluster_name' => 'Казань', 'region' => 'Нижний Новгород'],

            // Кластер: Самара (ID: 150)
            ['warehouse' => 'САМАРА_РФЦ', 'cluster_id' => 150, 'cluster_name' => 'Самара', 'region' => 'Самара'],
            ['warehouse' => 'САМАРА_РФЦ_НЕГАБАРИТ', 'cluster_id' => 150, 'cluster_name' => 'Самара', 'region' => 'Самара', 'negabarit' => true],
            ['warehouse' => 'САМАРА_РФЦ_ЮВЕЛИРНЫЙ', 'cluster_id' => 150, 'cluster_name' => 'Самара', 'region' => 'Самара', 'jewelry' => true],
            ['warehouse' => 'САРАТОВ_РФЦ', 'cluster_id' => 150, 'cluster_name' => 'Самара', 'region' => 'Саратов'],

            // Кластер: Уфа (ID: 148)
            ['warehouse' => 'ОРЕНБУРГ_РФЦ', 'cluster_id' => 148, 'cluster_name' => 'Уфа', 'region' => 'Оренбург'],
            ['warehouse' => 'УФА_РФЦ', 'cluster_id' => 148, 'cluster_name' => 'Уфа', 'region' => 'Уфа'],

            // Кластер: Краснодар/Юг (ID: 17)
            ['warehouse' => 'АДЫГЕЙСК_РФЦ', 'cluster_id' => 17, 'cluster_name' => 'Краснодар', 'region' => 'Адыгейск'],
            ['warehouse' => 'НОВОРОССИЙСК_МРФЦ', 'cluster_id' => 17, 'cluster_name' => 'Краснодар', 'region' => 'Новороссийск'],
            ['warehouse' => 'ЮЖНЫЙ_ОБХОД_РФЦ_НЕГАБАРИТ', 'cluster_id' => 17, 'cluster_name' => 'Краснодар', 'region' => 'Краснодар', 'negabarit' => true],
            ['warehouse' => 'АДЫГЕЙСК_РФЦ_ЮВЕЛИРНЫЙ', 'cluster_id' => 17, 'cluster_name' => 'Краснодар', 'region' => 'Адыгейск', 'jewelry' => true],
            ['warehouse' => 'КРАСНОДАР_2_РФЦ', 'cluster_id' => 17, 'cluster_name' => 'Краснодар', 'region' => 'Краснодар'],

            // Кластер: Ростов (ID: 147)
            ['warehouse' => 'РОСТОВ_НА_ДОНУ_РФЦ', 'cluster_id' => 147, 'cluster_name' => 'Ростов', 'region' => 'Ростов-на-Дону'],
            ['warehouse' => 'РОСТОВ_НА_ДОНУ_2_РФЦ', 'cluster_id' => 147, 'cluster_name' => 'Ростов', 'region' => 'Ростов-на-Дону'],

            // Кластер: Воронеж (ID: 16)
            ['warehouse' => 'ВОРОНЕЖ_2_РФЦ', 'cluster_id' => 16, 'cluster_name' => 'Воронеж', 'region' => 'Воронеж'],
            ['warehouse' => 'ВОРОНЕЖ_МРФЦ', 'cluster_id' => 16, 'cluster_name' => 'Воронеж', 'region' => 'Воронеж'],
            ['warehouse' => 'ВОРОНЕЖ_РФЦ_НЕГАБАРИТ', 'cluster_id' => 16, 'cluster_name' => 'Воронеж', 'region' => 'Воронеж', 'negabarit' => true],

            // Кластер: Волгоград/Саратов (ID: 152)
            ['warehouse' => 'ВОЛГОГРАД_МРФЦ', 'cluster_id' => 152, 'cluster_name' => 'Саратов', 'region' => 'Волгоград'],

            // Кластер: Кавказ (ID: 153)
            ['warehouse' => 'НЕВИННОМЫССК_РФЦ', 'cluster_id' => 153, 'cluster_name' => 'Кавказ', 'region' => 'Невинномысск'],

            // Кластер: Красноярск (ID: 146)
            ['warehouse' => 'КРАСНОЯРСК_МРФЦ', 'cluster_id' => 146, 'cluster_name' => 'Красноярск', 'region' => 'Красноярск'],

            // Кластер: Новосибирск/Сибирь (ID: 151)
            ['warehouse' => 'НОВОСИБИРСК_РФЦ_НОВЫЙ', 'cluster_id' => 151, 'cluster_name' => 'Новосибирск', 'region' => 'Новосибирск'],
            ['warehouse' => 'НОВОСИБИРСК_РФЦ_ЮВЕЛИРНЫЙ', 'cluster_id' => 151, 'cluster_name' => 'Новосибирск', 'region' => 'Новосибирск', 'jewelry' => true],
            ['warehouse' => 'ОМСК_РФЦ', 'cluster_id' => 151, 'cluster_name' => 'Новосибирск', 'region' => 'Омск'],

            // Кластер: Екатеринбург/Урал (ID: 176)
            ['warehouse' => 'ЕКАТЕРИНБУРГ_РФЦ_НЕГАБАРИТ', 'cluster_id' => 176, 'cluster_name' => 'Екатеринбург', 'region' => 'Екатеринбург', 'negabarit' => true],
            ['warehouse' => 'ЕКАТЕРИНБУРГ_РФЦ_НОВЫЙ', 'cluster_id' => 176, 'cluster_name' => 'Екатеринбург', 'region' => 'Екатеринбург'],
            ['warehouse' => 'ПЕРМЬ_РФЦ', 'cluster_id' => 176, 'cluster_name' => 'Екатеринбург', 'region' => 'Пермь'],
            ['warehouse' => 'ЕКАТЕРИНБУРГ_РФЦ_ЮВЕЛИРНЫЙ', 'cluster_id' => 176, 'cluster_name' => 'Екатеринбург', 'region' => 'Екатеринбург', 'jewelry' => true],

            // Кластер: Тюмень (ID: 144)
            ['warehouse' => 'ТЮМЕНЬ_РФЦ', 'cluster_id' => 144, 'cluster_name' => 'Тюмень', 'region' => 'Тюмень'],

            // Кластер: Дальний Восток (ID: 7)
            ['warehouse' => 'ХАБАРОВСК_2_РФЦ', 'cluster_id' => 7, 'cluster_name' => 'Дальний Восток', 'region' => 'Хабаровск'],

            // Кластер: Калининград (ID: 155)
            ['warehouse' => 'КАЛИНИНГРАД_МРФЦ', 'cluster_id' => 155, 'cluster_name' => 'Калининград', 'region' => 'Калининград'],

            // Кластер: Ярославль (ID: 156)
            ['warehouse' => 'ЯРОСЛАВЛЬ_РФЦ', 'cluster_id' => 156, 'cluster_name' => 'Ярославль', 'region' => 'Ярославль'],

            // Кластер: Беларусь (ID: 157)
            ['warehouse' => 'МИНСК_МПСЦ', 'cluster_id' => 157, 'cluster_name' => 'Беларусь', 'region' => 'Минск'],
            ['warehouse' => 'МИНСК_МРФЦ_НЕГАБАРИТ', 'cluster_id' => 157, 'cluster_name' => 'Беларусь', 'region' => 'Минск', 'negabarit' => true],

            // Кластер: Казахстан (ID: 158)
            ['warehouse' => 'АЛМАТЫ_2_РФЦ', 'cluster_id' => 158, 'cluster_name' => 'Казахстан', 'region' => 'Алматы'],
            ['warehouse' => 'АЛМАТЫ_МРФЦ', 'cluster_id' => 158, 'cluster_name' => 'Казахстан', 'region' => 'Алматы'],
            ['warehouse' => 'АСТАНА_РФЦ', 'cluster_id' => 158, 'cluster_name' => 'Казахстан', 'region' => 'Астана'],

            // Кластер: Армения (ID: 159)
            ['warehouse' => 'ЕРЕВАН_МРФЦ', 'cluster_id' => 159, 'cluster_name' => 'Армения', 'region' => 'Ереван'],
        ];

        $now = now();
        foreach ($clusters as $item) {
            DB::table('ozon_warehouse_clusters')->insert([
                'warehouse_name' => $item['warehouse'],
                'warehouse_name_normalized' => $this->normalizeWarehouseName($item['warehouse']),
                'cluster_id' => $item['cluster_id'],
                'cluster_name' => $item['cluster_name'],
                'region' => $item['region'] ?? null,
                'is_negabarit' => $item['negabarit'] ?? false,
                'is_jewelry' => $item['jewelry'] ?? false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Нормализация названия склада для поиска
     */
    private function normalizeWarehouseName(string $name): string
    {
        // Приводим к верхнему регистру, заменяем пробелы на подчёркивания
        $normalized = mb_strtoupper($name);
        $normalized = str_replace([' ', '-'], '_', $normalized);
        // Убираем лишние подчёркивания
        $normalized = preg_replace('/_+/', '_', $normalized);
        return trim($normalized, '_');
    }
};
