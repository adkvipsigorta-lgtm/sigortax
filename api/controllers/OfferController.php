<?php

class OfferController
{
    public function index(array $user, array $query): void
    {
        Response::error('Teklifler artik gorev sistemi uzerinden yonetilmektedir', 410);
    }

    public function show(array $user, int $id): void
    {
        Response::error('Teklifler artik gorev sistemi uzerinden yonetilmektedir', 410);
    }

    public function store(array $user, array $input): void
    {
        Response::error('Teklifler artik gorev sistemi uzerinden yonetilmektedir', 410);
    }

    public function update(array $user, int $id, array $input): void
    {
        Response::error('Teklifler artik gorev sistemi uzerinden yonetilmektedir', 410);
    }

    public function destroy(array $user, int $id): void
    {
        Response::error('Teklifler artik gorev sistemi uzerinden yonetilmektedir', 410);
    }

    public function convert(array $user, int $id, array $input): void
    {
        Response::error('Teklifler artik gorev sistemi uzerinden yonetilmektedir', 410);
    }

    private function formatOffer(array $o): array
    {
        return [];
    }
}
