<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

defined('ABSPATH') || exit;

final class WebhookController
{
    /** @var callable|null */
    private static $clientFactory;

    public static function register_routes()
    {
        register_rest_route('paymos-edd/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle(\WP_REST_Request $request)
    {
        $secrets = Config::webhook_secrets();
        if (count($secrets) === 0) {
            Logger::error('Paymos webhook received but webhook secrets are not configured.');
            return new \WP_REST_Response(array('error' => 'not_configured'), 500);
        }

        $eventStore = new EventStore();

        try {
            $verified = (new MultiEnvironmentWebhookVerifier($secrets, $eventStore))
                ->process($request->get_header('x-webhook-signature'), $request->get_body());
            $environment = $verified->environment();
            $event = $verified->event();

            if (!$event->isInvoiceEvent()) {
                $eventStore->commit();
                return new \WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
            }

            self::assertPayloadEnvironment($event, $environment);
            self::applyEvent($event, $environment);
            $eventStore->commit();
        } catch (DuplicateEventException $e) {
            Logger::info('Paymos duplicate webhook ignored.');
            return new \WP_REST_Response(array('ok' => true, 'duplicate' => true), 200);
        } catch (SignatureMismatchException $e) {
            Logger::error('Paymos webhook signature mismatch.');
            return new \WP_REST_Response(array('error' => 'bad_signature'), 401);
        } catch (TimestampSkewException $e) {
            Logger::error('Paymos webhook timestamp skew.');
            return new \WP_REST_Response(array('error' => 'bad_timestamp'), 401);
        } catch (\RuntimeException $e) {
            $eventStore->release();
            Logger::error('Paymos webhook processing failed: ' . $e->getMessage());
            return new \WP_REST_Response(array('error' => 'processing_failed'), 400);
        }

        return new \WP_REST_Response(array('ok' => true), 200);
    }

    private static function applyEvent(WebhookEvent $event, $environment)
    {
        $paymentId = PaymentRepository::paymentIdFromExternalOrderId($event->externalOrderId());
        if ($paymentId <= 0) {
            throw new \RuntimeException('EDD payment id could not be parsed from Paymos external order id.');
        }

        self::assertPaymentMatchesEvent($paymentId, $event, $environment);
        self::reverseVerifyTerminalEvent($event, $paymentId, $environment);

        (new PaymentMapper())->apply($paymentId, $event->toArray());
    }

    private static function assertPaymentMatchesEvent($paymentId, WebhookEvent $event, $environment)
    {
        $paymentEnvironment = PaymentRepository::getMeta($paymentId, '_paymos_environment');
        if ($paymentEnvironment !== '' && $paymentEnvironment !== (string) $environment) {
            throw new \RuntimeException('Paymos webhook environment does not match EDD payment environment.');
        }

        $paymentProjectId = PaymentRepository::getMeta($paymentId, '_paymos_project_id');
        if ($paymentProjectId !== '' && $event->projectId() !== '' && $paymentProjectId !== $event->projectId()) {
            throw new \RuntimeException('Paymos webhook project does not match EDD payment project.');
        }
    }

    private static function assertPayloadEnvironment(WebhookEvent $event, $environment)
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }

        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }

        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    private static function reverseVerifyTerminalEvent(WebhookEvent $event, $paymentId, $environment)
    {
        if (!self::requiresReverseVerify($event)) {
            return;
        }

        $result = (new InvoiceReverseVerifier(self::client($environment)))->verify($event, array(
            'project_id' => PaymentRepository::getMeta($paymentId, '_paymos_project_id'),
            'external_order_id' => $event->externalOrderId(),
            'amount' => PaymentRepository::getMeta($paymentId, '_paymos_invoice_amount'),
            'currency' => PaymentRepository::getMeta($paymentId, '_paymos_invoice_currency'),
        ));

        if (!$result->isVerified()) {
            throw new \RuntimeException('Paymos reverse invoice verification failed: ' . $result->reason());
        }
    }

    private static function requiresReverseVerify(WebhookEvent $event)
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    private static function client($environment)
    {
        if (self::$clientFactory !== null) {
            return call_user_func(self::$clientFactory, $environment);
        }

        $config = Config::environment_config($environment);
        foreach (array('api_key', 'api_secret', 'base_url') as $required) {
            if (!isset($config[$required]) || !is_scalar($config[$required]) || trim((string) $config[$required]) === '') {
                throw new \RuntimeException('Paymos generated config is missing ' . $required . ' for ' . (string) $environment . '.');
            }
        }

        return new Client(new ClientConfig(
            (string) $config['api_key'],
            (string) $config['api_secret'],
            (string) $config['base_url'],
            30
        ));
    }

    public static function set_client_factory_for_tests($factory)
    {
        self::$clientFactory = $factory;
    }
}
