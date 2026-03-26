<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\RouteListCommand as BaseRouteListCommand;
use Symfony\Component\Console\Input\InputOption;

class RouteListCommand extends BaseRouteListCommand
{
    /**
     * @return array<int, array{0: string, 1: string|null, 2: int, 3: string}>
     */
    protected function getOptions()
    {
        return array_merge(parent::getOptions(), [
            ['columns', null, InputOption::VALUE_OPTIONAL, 'Columns to include (comma-separated): domain, method, uri, name, action, middleware'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function getColumns()
    {
        $columns = $this->option('columns');
        if (! filled($columns)) {
            return parent::getColumns();
        }

        $parsed = array_map(trim(...), $this->parseColumns(is_array($columns) ? $columns : [$columns]));
        $allowed = array_map(strtolower(...), $this->headers);
        $filtered = array_values(array_intersect($parsed, $allowed));

        return $filtered !== [] ? $filtered : parent::getColumns();
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<int, array<string, mixed>>
     */
    protected function pluckColumns(array $routes)
    {
        $rows = parent::pluckColumns($routes);

        if (! filled($this->option('columns')) || $this->option('json')) {
            return $rows;
        }

        $pad = array_fill_keys(['domain', 'method', 'uri', 'name', 'action', 'middleware'], '');

        return array_map(fn (array $row) => array_merge($pad, $row), $rows);
    }
}
