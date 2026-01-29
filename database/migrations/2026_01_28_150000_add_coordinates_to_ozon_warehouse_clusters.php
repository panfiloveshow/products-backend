<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ozon_warehouse_clusters', function (Blueprint $table) {
            $table->decimal('latitude', 10, 6)->nullable()->after('region')->comment('Широта');
            $table->decimal('longitude', 10, 6)->nullable()->after('latitude')->comment('Долгота');
        });

        // Добавляем координаты для кластеров
        $this->seedCoordinates();
    }

    public function down(): void
    {
        Schema::table('ozon_warehouse_clusters', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }

    private function seedCoordinates(): void
    {
        // Координаты центров кластеров (городов)
        $clusterCoordinates = [
            // Россия
            154 => ['lat' => 55.7558, 'lng' => 37.6173, 'name' => 'Москва'],
            2 => ['lat' => 59.9343, 'lng' => 30.3351, 'name' => 'Санкт-Петербург'],
            149 => ['lat' => 55.7879, 'lng' => 49.1233, 'name' => 'Казань'],
            150 => ['lat' => 53.1959, 'lng' => 50.1002, 'name' => 'Самара'],
            148 => ['lat' => 54.7388, 'lng' => 55.9721, 'name' => 'Уфа'],
            17 => ['lat' => 45.0355, 'lng' => 38.9753, 'name' => 'Краснодар'],
            147 => ['lat' => 47.2357, 'lng' => 39.7015, 'name' => 'Ростов-на-Дону'],
            16 => ['lat' => 51.6720, 'lng' => 39.1843, 'name' => 'Воронеж'],
            171 => ['lat' => 51.5336, 'lng' => 46.0343, 'name' => 'Саратов'],
            191 => ['lat' => 44.6324, 'lng' => 41.9447, 'name' => 'Невинномысск'],
            155 => ['lat' => 56.0153, 'lng' => 92.8932, 'name' => 'Красноярск'],
            151 => ['lat' => 55.0084, 'lng' => 82.9357, 'name' => 'Новосибирск'],
            152 => ['lat' => 54.9885, 'lng' => 73.3242, 'name' => 'Омск'],
            176 => ['lat' => 56.8389, 'lng' => 60.6057, 'name' => 'Екатеринбург'],
            180 => ['lat' => 58.0105, 'lng' => 56.2502, 'name' => 'Пермь'],
            144 => ['lat' => 57.1522, 'lng' => 65.5272, 'name' => 'Тюмень'],
            7 => ['lat' => 48.4827, 'lng' => 135.0838, 'name' => 'Хабаровск'],
            12 => ['lat' => 54.7104, 'lng' => 20.4522, 'name' => 'Калининград'],
            170 => ['lat' => 57.6261, 'lng' => 39.8845, 'name' => 'Ярославль'],
            174 => ['lat' => 56.8587, 'lng' => 35.9176, 'name' => 'Тверь'],
            179 => ['lat' => 51.7682, 'lng' => 55.0969, 'name' => 'Оренбург'],
            // СНГ
            157 => ['lat' => 53.9045, 'lng' => 27.5615, 'name' => 'Минск'],
            158 => ['lat' => 43.2380, 'lng' => 76.9454, 'name' => 'Алматы'],
            159 => ['lat' => 40.1792, 'lng' => 44.4991, 'name' => 'Ереван'],
        ];

        foreach ($clusterCoordinates as $clusterId => $coords) {
            DB::table('ozon_warehouse_clusters')
                ->where('cluster_id', $clusterId)
                ->update([
                    'latitude' => $coords['lat'],
                    'longitude' => $coords['lng'],
                ]);
        }
    }
};
