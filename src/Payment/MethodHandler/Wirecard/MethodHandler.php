<?php
namespace Ecommerce\Payment\MethodHandler\Wirecard;

use Ecommerce\Common\UrlProvider;
use Ecommerce\Payment\CallbackType;
use Ecommerce\Payment\Method;
use Ecommerce\Payment\MethodHandler\HandleCallbackData;
use Ecommerce\Payment\MethodHandler\HandleCallbackResult;
use Ecommerce\Payment\MethodHandler\InitData;
use Ecommerce\Payment\MethodHandler\InitResult;
use Ecommerce\Payment\MethodHandler\MethodHandler as MethodHandlerInterface;
use Ecommerce\Payment\PostPayment\Handler;
use Ecommerce\Payment\PostPayment\SuccessfulData;
use Ecommerce\Transaction\Provider;
use Ecommerce\Transaction\SaveData;
use Ecommerce\Transaction\Saver;
use Ecommerce\Transaction\Status;
use Exception;
use Log\Log;
use Prophecy\Call\Call;

class MethodHandler implements MethodHandlerInterface
{
	/**
	 * @var array
	 */
	private $config;

	/**
	 * @var Saver
	 */
	private $saver;

	/**
	 * @var UrlProvider
	 */
	private $urlProvider;

	/**
	 * @var Provider
	 */
	private $transactionProvider;

	/**
	 * @param array $config
	 * @param Saver $saver
	 * @param UrlProvider $urlProvider
	 * @param Provider $transactionProvider
	 */
	public function __construct(array $config, Saver $saver, UrlProvider $urlProvider, Provider $transactionProvider)
	{
		$this->config              = $config;
		$this->saver               = $saver;
		$this->urlProvider         = $urlProvider;
		$this->transactionProvider = $transactionProvider;
	}

	/**
	 * @param InitData $data
	 * @return InitResult
	 * @throws Exception
	 */
	public function init(InitData $data): InitResult
	{
		$initResult = new InitResult();
		$initResult->setSuccess(false);

		$saveResult = $this->saver->save(
			SaveData::create()
				->setTransaction($transaction = $data->getTransaction())
				->setStatus(Status::PENDING)
		);

		if (!$saveResult->isSuccess())
		{
			$initResult->setErrors($saveResult->getErrors());

			return $initResult;
		}

		// reload transaction
		$transaction = $this->transactionProvider->byId(
			$transaction->getId()
		);

		$options = $this->config['ecommerce']['payment']['method'][Method::WIRECARD]['options'];

		$host = ($options['sandbox'] ?? true)
			? $options['hostTest']
			: $options['hostLive'];

		$transactionId = $transaction
			->getId()
			->toString();

		$ch = curl_init();

		$body = json_encode(
			[
				'payment' => [
					'merchant-account-id'  => [
						'value' => $options['merchantAccountId'],
					],
					'request-id'           => $transactionId,
					'transaction-type'     => 'authorization',
					'requested-amount'     => [
						'value'    => $transaction
								->getTotalPrice()
								->getGross() / 100, // amount is cents, so divide by 100
						'currency' => $transaction
							->getTotalPrice()
							->getCurrency(),
					],
					'success-redirect-url' => $this->getUrl(CallbackType::SUCCESS, $transactionId),
					'fail-redirect-url'    => $this->getUrl(CallbackType::ERROR, $transactionId),
					'cancel-redirect-url'  => $this->getUrl(CallbackType::CANCEL, $transactionId),
				],
			]
		);

		curl_setopt($ch, CURLOPT_URL, $host . '/api/payment/register');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			[
				'Authorization: Basic ' . base64_encode($options['username'] . ":" . $options['password']),
				'Content-Type: application/json',
				'Content-Length: ' . strlen($body)
			]
		);
		curl_setopt(
			$ch,
			CURLOPT_POSTFIELDS,
			$body
		);

		$content = curl_exec($ch);

		curl_close($ch);

		Log::debug($content);

		if (!$content || !($decoded = json_decode($content)))
		{
			return $initResult;
		}

		if (($errors = $decoded->errors ?? []))
		{
			foreach ($errors as $error)
			{
				Log::error('Wirecard init error: ' . $error->code . ' - ' . $error->description);
			}

			return $initResult;
		}

		if (!($redirectUrl = $decoded->{'payment-redirect-url'} ?? null))
		{
			Log::error('Wirecard init error: No payment redirect url set');

			return $initResult;
		}

		$initResult->setSuccess(true);
		$initResult->setRedirectUrl($redirectUrl);

		return $initResult;
	}

	/**
	 * @param HandleCallbackData $data
	 * @return HandleCallbackResult
	 */
	public function handleCallback(HandleCallbackData $data): HandleCallbackResult
	{
		$result = new HandleCallbackResult();
		$result->setTransactionStatus(Status::ERROR);

		$request = $data->getRequest();

		$base64Decoded = base64_decode(
			$request
				->getPost('response-base64')
		);

		Log::debug('Wirecard callback response: ' . json_encode($response));

		$response = json_decode($base64Decoded);

		if (!$response || !($response->payment))
		{
			return $result;
		}

		$transactionId = $data
			->getTransaction()
			->getId()
			->toString();

		$payment = $response->payment;

		if ($payment->{'request-id'} !== $transactionId)
		{
			return $result;
		}

		$result->setForeignId($payment->{'transaction-id'} ?? null);

		if ($data->getType() === CallbackType::CANCEL)
		{
			$result->setTransactionStatus(
				Status::CANCELLED
			);

			return $result;
		}

		switch ($payment->{'transaction-state'})
		{
			case 'success':
				$result->setTransactionStatus(
					Status::SUCCESS
				);

				break;

			case 'failed':
				$result->setTransactionStatus(
					Status::ERROR
				);

				break;

			default:
				$result->setTransactionStatus(
					Status::PENDING
				);

				// TODO start async queue process or something like that?
		}

		return $result;
	}

	/**
	 * @param string $type
	 * @param string $transactionId
	 * @return string $type
	 */
	private function getUrl($type, $transactionId)
	{
		return $this->urlProvider->get(
			'ecommerce/payment/callback',
			[
				'transactionId' => $transactionId,
				'method'        => Method::WIRECARD,
				'type'          => $type,
			]
		);
	}
}