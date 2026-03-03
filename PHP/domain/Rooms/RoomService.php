<?php

require_once __DIR__ . '/../../core/Validator.php';
require_once __DIR__ . '/RoomRepository.php';

final class RoomService
{
    private RoomRepository $repo;

    public function __construct(RoomRepository $repo)
    {
        $this->repo = $repo;
    }

    public static function allowedStatuses(): array
    {
        return ['Vacant', 'Occupied', 'Cleaning', 'Out of Order'];
    }

    public function list(string $q = ''): array
    {
        return $this->repo->search($q);
    }

    public function get(int $id): ?array
    {
        return $this->repo->findById($id);
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (!Validator::required($data['room_no'] ?? null)) {
            $errors['room_no'] = 'Room number is required.';
        }
        $rt = $data['room_type_id'] ?? 0;
        if (!is_numeric($rt) || (int)$rt <= 0) {
            $errors['room_type_id'] = 'Room type is required.';
        }

        if (!Validator::inArray($data['status'] ?? 'Vacant', self::allowedStatuses())) {
            $errors['status'] = 'Status is invalid.';
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
