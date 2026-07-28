<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Paths;
use Throwable;

/**
 * Friendly HTML/JSON error handler — never shows the raw CI debug exception dump.
 */
class AppExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    use ResponseTrait;

    private ?RequestInterface $request = null;

    private ?ResponseInterface $response = null;

    /**
     * @param CLIRequest|IncomingRequest $request
     */
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        $this->request  = $request;
        $this->response = $response;

        if ($request instanceof IncomingRequest) {
            try {
                $response->setStatusCode($statusCode);
            } catch (HTTPException) {
                $statusCode = 500;
                $response->setStatusCode($statusCode);
            }

            if (! headers_sent()) {
                header(
                    sprintf(
                        'HTTP/%s %s %s',
                        $request->getProtocolVersion(),
                        $response->getStatusCode(),
                        $response->getReasonPhrase(),
                    ),
                    true,
                    $statusCode,
                );
            }

            if ($this->wantsJson($request)) {
                $this->sendJsonError($exception, $statusCode, $exitCode);

                return;
            }
        }

        if ($request instanceof CLIRequest || is_cli()) {
            $error = ErrorPresenter::present($exception, $statusCode);
            fwrite(STDERR, sprintf(
                "[%s] %s\n%s\n%s\n",
                $error['code'],
                $error['headline'],
                $error['message'],
                $error['show_details'] ? $error['technical'] : '',
            ));

            if (\ENVIRONMENT !== 'testing') {
                exit($exitCode);
            }

            return;
        }

        $addPath = 'html' . DIRECTORY_SEPARATOR;
        $path    = $this->viewPath . $addPath;
        $altPath = rtrim((new Paths())->viewDirectory, '\\/ ')
            . DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR . $addPath;

        // 404 keeps its dedicated screen; everything else uses the reusable app error page.
        $view = $exception instanceof PageNotFoundException ? 'error_404.php' : 'app_error.php';

        $viewFile = null;
        if (is_file($path . $view)) {
            $viewFile = $path . $view;
        } elseif (is_file($altPath . $view)) {
            $viewFile = $altPath . $view;
        } elseif (is_file($path . 'app_error.php')) {
            $viewFile = $path . 'app_error.php';
        }

        $this->renderAppError($exception, $statusCode, $viewFile);

        if (\ENVIRONMENT !== 'testing') {
            exit($exitCode);
        }
    }

    protected function wantsJson(IncomingRequest $request): bool
    {
        if ($request->isAJAX()) {
            return true;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        if ($accept === '') {
            return false;
        }

        if (str_contains($accept, 'text/html')) {
            return false;
        }

        return str_contains($accept, 'application/json')
            || str_contains($accept, 'application/vnd.api+json');
    }

    protected function sendJsonError(Throwable $exception, int $statusCode, int $exitCode): void
    {
        $error   = ErrorPresenter::present($exception, $statusCode);
        $payload = [
            'success' => false,
            'message' => $error['message'],
            'error'   => [
                'code'  => $error['code'],
                'kind'  => $error['kind'],
                'title' => $error['title'],
                'hint'  => $error['hint'],
            ],
        ];

        if ($error['show_details']) {
            $payload['error']['technical'] = $error['technical'];
            $payload['error']['exception'] = $error['exception_class'];
        }

        $this->respond($payload, $statusCode)->send();

        if (\ENVIRONMENT !== 'testing') {
            exit($exitCode);
        }
    }

    protected function renderAppError(Throwable $exception, int $statusCode, ?string $viewFile): void
    {
        if ($viewFile === null || ! is_file($viewFile)) {
            echo 'Application error. Unable to load the error screen.';
            exit(1);
        }

        echo (function () use ($exception, $statusCode, $viewFile): string {
            $vars  = $this->collectVars($exception, $statusCode);
            $error = ErrorPresenter::present($exception, $statusCode);
            extract($vars, EXTR_SKIP);

            ob_start();
            include $viewFile;

            return (string) ob_get_clean();
        })();
    }
}
