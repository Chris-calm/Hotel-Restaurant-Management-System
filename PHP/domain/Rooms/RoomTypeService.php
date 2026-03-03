<?php

require_once __DIR__ . '/../../core/Validator.php';
require_once __DIR__ . '/RoomTypeRepository.php';

final class RoomTypeService
{
    private RoomTypeRepository $repo;

    public function __construct(RoomTypeRepository $repo)
    {
        $this->repo = $repo;
    }

    public function list(): array
    {
        return $this->repo->all();
    }

    public function get(int $id): ?array
    {
        return $this->repo->findById($id);
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (!Validator::required($data['code'] ?? null)) {
            $errors['code'] = 'Code is required.';
        }
        if (!Validator::required($data['name'] ?? null)) {
            $errors['name'] = 'Name is required.';
        }

        $rate = $data['base_rate'] ?? 0;
        if (!is_numeric($rate) || (float)$rate < 0) {
            $errors['base_rate'] = 'Base rate must be a non-negative number.';
        }

        $adults = $data['max_adults'] ?? 0;
        if (!is_numeric($adults) || (int)$adults < 1) {
            $errors['max_adults'] = 'Max adults must be at least 1.';
        }

        $children = $data['max_children'] ?? 0;
        if (!is_numeric($children) || (int)$children < 0) {
            $errors['max_children'] = 'Max children must be 0 or more.';
        }

        return $errors;
    }

    public function create(array $data, array &$errors): int
    {
        $errors = $this->validate($data);
        if (!empty($errors)) {
            return 0;
        }
        return $this->repo->create($data);
    }

    public function update(int $id, array $data, array &$errors): bool
    {
        $errors = $this->validate($data);
        if (!empty($errors)) {
            return false;
        }
        return $this->repo->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->repo->delete($id);
    }
}
