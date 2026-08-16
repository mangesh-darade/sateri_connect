<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Base controller for authenticated panel views.
 */
abstract class BaseController extends Controller
{
    /**
     * @var list<string>
     */
    protected $helpers = ['form', 'url', 'permission', 'settings', 'whatsapp', 'text'];

    /**
     * @var \CodeIgniter\Session\Session
     */
    protected $session;

    /**
     * Current authenticated user row (without password).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $currentUser = null;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->session = service('session');

        if ($this->session->get('user_id')) {
            $this->currentUser = [
                'id'         => (int) $this->session->get('user_id'),
                'name'       => (string) $this->session->get('user_name'),
                'email'      => (string) $this->session->get('user_email'),
                'role_id'    => $this->session->get('role_id'),
                'role_name'  => $this->session->get('role_name'),
                'role_slug'  => $this->session->get('role_slug'),
                'avatar'     => $this->session->get('user_avatar'),
                'permissions'=> $this->session->get('permissions') ?? [],
            ];
        }
    }

    /**
     * Render a view that extends layouts/main (section-based).
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = []): string
    {
        $data['user']         = $data['user'] ?? $this->currentUser;
        $data['permissions']  = $data['permissions'] ?? ($this->currentUser['permissions'] ?? []);
        $data['pageTitle']    = $data['pageTitle'] ?? 'WhatsApp Automation';
        $data['title']        = $data['title'] ?? $data['pageTitle'];
        $data['csrfToken']    = csrf_hash();
        $data['csrfName']     = csrf_token();

        if (! isset($data['waAccount'])) {
            try {
                $identitySvc = new \App\Libraries\WhatsAppIdentityService();
                $identity    = $identitySvc->getIdentity();
                $data['waAccount'] = $identity;
                $data['waIdentityNeedsRefresh'] = ! empty($identity['needs_refresh']);
            } catch (\Throwable $e) {
                $data['waAccount'] = [
                    'provider'            => 'cheerio',
                    'display_name'        => '',
                    'phone'               => '',
                    'profile_picture_url' => '',
                    'connected'           => false,
                    'needs_refresh'       => false,
                ];
                $data['waIdentityNeedsRefresh'] = false;
            }
        } else {
            $data['waIdentityNeedsRefresh'] = $data['waIdentityNeedsRefresh'] ?? false;
        }

        if (! isset($data['notifications'])) {
            $uid = (int) ($this->currentUser['id'] ?? 0);
            $notifModel = model(\App\Models\NotificationModel::class);
            $data['notifications'] = $uid > 0
                ? $notifModel->enrichForUi($notifModel->getUnreadForUser($uid, 12))
                : [];
        }
        if (! isset($data['unread_notifications'])) {
            $uid = (int) ($this->currentUser['id'] ?? 0);
            $data['unread_notifications'] = $uid > 0
                ? (int) model(\App\Models\NotificationModel::class)
                    ->where('user_id', $uid)
                    ->where('is_read', 0)
                    ->countAllResults()
                : 0;
        }

        return view($view, $data);
    }

    /**
     * JSON response helper for AJAX endpoints on web controllers.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $errors
     */
    protected function jsonResponse(
        bool $success,
        mixed $data = null,
        string $message = '',
        array $errors = [],
        int $status = 200
    ): ResponseInterface {
        $payload = [
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    /**
     * Read request body as array from JSON or form/multipart POST.
     * Avoids CI throwing "Failed to parse JSON string" on form-urlencoded AJAX.
     *
     * @return array<string, mixed>
     */
    protected function requestInput(): array
    {
        $contentType = strtolower($this->request->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'application/json')) {
            try {
                $json = $this->request->getJSON(true);
                return is_array($json) ? $json : [];
            } catch (\Throwable) {
                return [];
            }
        }

        $post = $this->request->getPost();

        return is_array($post) ? $post : [];
    }

    /**
     * Require a permission slug; abort with redirect or JSON 403.
     */
    protected function requirePermission(string $permission): ?ResponseInterface
    {
        if (can($permission)) {
            return null;
        }

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(false, null, 'Permission denied.', [], 403);
        }

        return redirect()->to('/dashboard')->with('error', 'You do not have permission to access this resource.');
    }

    /**
     * @param list<string> $permissions
     */
    protected function requireAnyPermission(array $permissions): ?ResponseInterface
    {
        foreach ($permissions as $permission) {
            if (is_string($permission) && $permission !== '' && can($permission)) {
                return null;
            }
        }

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(false, null, 'Permission denied.', [], 403);
        }

        return redirect()->to('/dashboard')->with('error', 'You do not have permission to access this resource.');
    }

    protected function userId(): ?int
    {
        $id = $this->session->get('user_id');

        return $id ? (int) $id : null;
    }
}
