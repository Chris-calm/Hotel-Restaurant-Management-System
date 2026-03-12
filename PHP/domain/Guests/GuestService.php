<?php

require_once __DIR__ . '/../../core/Validator.php';
require_once __DIR__ . '/GuestRepository.php';

final class GuestService
{
    private GuestRepository $repo;

    public function __construct(GuestRepository $repo)
    {
        $this->repo = $repo;
    }

    public static function allowedStatuses(): array
    {
        return ['Lead', 'Booked', 'Checked In', 'Checked Out', 'Blacklisted'];
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (!Validator::required($data['first_name'] ?? null)) {
            $errors['first_name'] = 'First name is required.';
        }
        if (!Validator::required($data['last_name'] ?? null)) {
            $errors['last_name'] = 'Last name is required.';
        }
        if (!Validator::email($data['email'] ?? '')) {
            $errors['email'] = 'Email is invalid.';
        }
        if (!Validator::required($data['phone'] ?? null)) {
            $errors['phone'] = 'Phone is required.';
        }

        $idNumber = trim((string)($data['id_number'] ?? ''));
        $idType = trim((string)($data['id_type'] ?? ''));
        if ($idNumber !== '' && $idType === '') {
            $errors['id_type'] = 'ID type is required when ID number is provided.';
        }
        if (!Validator::inArray($data['status'] ?? 'Lead', self::allowedStatuses())) {
            $errors['status'] = 'Status is invalid.';
        }

        return $errors;
    }

    public function list(string $q = ''): array
    {
        return $this->repo->search($q);
    }

    public function get(int $id): ?array
    {
        return $this->repo->findById($id);
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
