<?php
/**
 * @author         Pierre-Henry Soria <hello@lifyzer.com>
 * @copyright      (c) 2018, Pierre-Henry Soria. All Rights Reserved.
 * @license        GNU General Public License; <https://www.gnu.org/licenses/gpl-3.0.en.html>
 * @link           https://lifyzer.com
 */

declare(strict_types=1);

namespace Lifyzer\Server\App\Controller;

use Lifyzer\Server\App\Model\Product as ProductModel;
use Lifyzer\Server\Core\Container\Provider\Monolog;
use Lifyzer\Server\Core\Container\Provider\SwiftMailer;
use PDOException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Swift_Mailer;
use Swift_Message;
use Symfony\Component\HttpFoundation\ParameterBag;

class Product extends Base
{
    private const ADD_PRODUCT_VIEW_FILE = 'product/add.twig';
    private const SUBMIT_PRODUCT_VIEW_FILE = 'product/submit.twig';
    private const EMAIL_NEW_PRODUCT_VIEW_FILE = 'emails/new-product-details.twig';
    private const EMAIL_SUBJECT = 'New Product to be moderated';
    private const HTML_CONTENT_TYPE = 'text/html';

    /** @var ProductModel */
    private $productModel;

    /** @var Swift_Mailer */
    private $mailer;

    /** @var ContainerInterface */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->mailer = $container->get(SwiftMailer::class);
        $this->productModel = new ProductModel($container);
        $this->container = $container;
    }

    public function add(): void
    {
        $this->view->display(
            self::ADD_PRODUCT_VIEW_FILE,
            [
                'siteUrl' => SITE_URL,
                'siteName' => SITE_NAME,
                'pageName' => 'Add a Product'
            ]
        );
    }

    public function submit(): void
    {
        $request = $this->httpRequest->request;

        if ($request->get('addproduct') && !$this->isSpamBot($request)) {
            $data = $request->all();

            // Remove unused params since we don't bind them
            unset($data['addproduct'], $data['firstname']);

            if (empty($data['barcode'])) {
                $data['barcode'] = '-';
            }

            if ($this->isFormCompleted($data)) {
                try {
                    if (
                        !$this->productModel->doesBarcodeExist($data['barcode']) &&
                        !$this->productModel->doesProductNameExist($data['name'])
                    ) {
                        $data['productId'] = $this->productModel->addToPending($data);
                        $this->sendEmail($data);
                        $message = 'Product successfully submitted. It will be reviewed shortly';
                    } else {
                        $message = 'Oops! This product has already been added to the database.';
                    }

                    $this->view->display(
                        self::SUBMIT_PRODUCT_VIEW_FILE,
                        [
                            'siteUrl' => SITE_URL,
                            'siteName' => SITE_NAME,
                            'pageName' => 'Add a Product',
                            'message' => $message
                        ]
                    );
                } catch (PDOException $except) {
                    /** @var LoggerInterface $log */
                    $log = $this->container->get(Monolog::class);
                    $log->error(
                        'PDO Exception',
                        [
                            'message' => $except->getMessage(),
                            'trace' => $except->getTraceAsString()
                        ]
                    );

                    (new Error($this->container))->internalError();
                    exit;
                }
            } else {
                $this->redirectToHomepage();
            }
        } else {
            $this->redirectToHomepage();
        }
    }

    public function approve(array $data): void
    {
        if ($this->isSecurityHashValid($data)) {
            $productId = (int)$data['id'];
            $result = $this->productModel->moveToLive($productId);
            echo $result ? 'Product approved! :)' : 'An error occurred...';
        } else {
            echo 'Invalid security hash!';
        }
    }

    public function disapprove(array $data): void
    {
        if ($this->isSecurityHashValid($data)) {
            $productId = (int)$data['id'];
            $result = $this->productModel->discard($productId);
            echo $result ? 'Product discard! :(' : 'An error occurred...';
        } else {
            echo 'Invalid security hash!';
        }
    }

    private function sendEmail(array $data): void
    {
        $adminEmail = getenv('ADMIN_EMAIL');

        $urls = [
            'approvalUrlHash' => $this->getApprovalUrl($data['productId']),
            'disapprovalUrlHash' => $this->getDisapprovalUrl($data['productId'])
        ];

        $message = (new Swift_Message(self::EMAIL_SUBJECT))
            ->setFrom($adminEmail)
            ->setTo($adminEmail)
            ->setBody(
                $this->view->render(
                    self::EMAIL_NEW_PRODUCT_VIEW_FILE,
                    array_merge($data, $urls)
                ),
                self::HTML_CONTENT_TYPE
            );

        $this->mailer->send($message);
    }

    private function getApprovalUrl(int $productId): string
    {
        return sprintf(
            '%sapprove/%s/%d',
            SITE_URL,
            getenv('SECURITY_HASH'),
            $productId
        );
    }

    private function getDisapprovalUrl(int $productId): string
    {
        return sprintf(
            '%sdisapprove/%s/%d',
            SITE_URL,
            getenv('SECURITY_HASH'),
            $productId
        );
    }

    /**
     * Make sure that a human fulfilled the form (a bot would fulfil "firstname" field as well).
     *
     * @param ParameterBag $request
     *
     * @return bool
     */
    private function isSpamBot(ParameterBag $request): bool
    {
        return (bool)$request->get('firstname');
    }

    private function isFormCompleted(array $fields): bool
    {
        foreach ($fields as $name => $value) {
            if (!isset($name) || trim($value) === '') {
                return false;
            }

            return true;
        }
    }

    private function isSecurityHashValid(array $data): bool
    {
        return $data['hash'] === getenv('SECURITY_HASH');
    }

    private function redirectToHomepage(): void
    {
        header('Location: ' . SITE_URL);
        exit;
    }
}
