<?php

class LocationController
{
    public function index(array $query = []): void
    {
        if (!empty($query['countryId'])) {
            $cities = Database::fetchAll(
                "SELECT id, name FROM cities WHERE country_id = ? ORDER BY name",
                [(int) $query['countryId']]
            );
        } else {
            $cities = Database::fetchAll(
                "SELECT id, name FROM cities ORDER BY name"
            );
        }
        Response::success($cities);
    }

    public function districts(int $cityId): void
    {
        $districts = Database::fetchAll(
            "SELECT id, name FROM districts WHERE city_id = ? ORDER BY name",
            [$cityId]
        );
        Response::success($districts);
    }

    public function countries(): void
    {
        $countries = Database::fetchAll(
            "SELECT id, name FROM countries WHERE deleted_at IS NULL ORDER BY name"
        );
        Response::success($countries);
    }
}
