<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ContactModel;
use App\Models\OrganizationModel;
use App\Support\View;

final class OrganizationController extends Controller
{
    public function index(): string
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $orgType = trim((string) ($_GET['type'] ?? ''));

        return $this->render('organizations/index', [
            'pageTitle' => 'Organizations',
            'organizations' => (new OrganizationModel($this->db))->searchWithContactCounts($query, $orgType),
            'orgTypes' => OrganizationModel::ORG_TYPES,
            'query' => $query,
            'orgType' => $orgType,
        ]);
    }

    public function show(array $vars): string
    {
        $organization = (new OrganizationModel($this->db))->find((int) $vars['id']);

        if ($organization === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        $contacts = (new ContactModel($this->db))->search('', '', (int) $organization['id']);

        return $this->render('organizations/show', [
            'pageTitle' => (string) $organization['name'],
            'organization' => $organization,
            'orgTypes' => OrganizationModel::ORG_TYPES,
            'contacts' => $contacts,
            'today' => $this->todayLocal()->format('Y-m-d'),
        ]);
    }

    public function create(): string
    {
        return $this->render('organizations/create', [
            'pageTitle' => 'Add organization',
            'orgTypes' => OrganizationModel::ORG_TYPES,
            'errors' => [],
            'old' => [],
        ]);
    }

    public function store(): string
    {
        $this->requireValidCsrf();

        [$data, $errors] = $this->validated($_POST);

        if ($errors !== []) {
            return $this->render('organizations/create', [
                'pageTitle' => 'Add organization',
                'orgTypes' => OrganizationModel::ORG_TYPES,
                'errors' => $errors,
                'old' => $_POST,
            ]);
        }

        $id = (new OrganizationModel($this->db))->create($data);

        return $this->redirect('/organizations/' . $id);
    }

    public function edit(array $vars): string
    {
        $organization = (new OrganizationModel($this->db))->find((int) $vars['id']);

        if ($organization === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        return $this->render('organizations/edit', [
            'pageTitle' => 'Edit ' . (string) $organization['name'],
            'organization' => $organization,
            'orgTypes' => OrganizationModel::ORG_TYPES,
            'errors' => [],
            'old' => $organization,
        ]);
    }

    public function update(array $vars): string
    {
        $this->requireValidCsrf();

        $organizations = new OrganizationModel($this->db);
        $organization = $organizations->find((int) $vars['id']);

        if ($organization === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        [$data, $errors] = $this->validated($_POST);

        if ($errors !== []) {
            return $this->render('organizations/edit', [
                'pageTitle' => 'Edit ' . (string) $organization['name'],
                'organization' => $organization,
                'orgTypes' => OrganizationModel::ORG_TYPES,
                'errors' => $errors,
                'old' => $_POST,
            ]);
        }

        $organizations->update((int) $organization['id'], $data);

        return $this->redirect('/organizations/' . (int) $organization['id']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validated(array $input): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        $orgType = trim((string) ($input['org_type'] ?? ''));
        if (!array_key_exists($orgType, OrganizationModel::ORG_TYPES)) {
            $errors['org_type'] = 'Pick a valid type.';
        }

        $website = trim((string) ($input['website'] ?? ''));
        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
            $errors['website'] = 'Website must be a full URL (https://…).';
        }

        $linkedin = trim((string) ($input['linkedin_url'] ?? ''));
        if ($linkedin !== '' && filter_var($linkedin, FILTER_VALIDATE_URL) === false) {
            $errors['linkedin_url'] = 'LinkedIn URL must be a full URL (https://…).';
        }

        $data = [
            'name' => $name,
            'org_type' => $orgType,
            'website' => $website === '' ? null : $website,
            'linkedin_url' => $linkedin === '' ? null : $linkedin,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        return [$data, $errors];
    }
}
