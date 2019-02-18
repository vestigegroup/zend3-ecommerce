<?php
namespace Ecommerce\Payment\PostPayment;

use Ecommerce\Customer\Customer;
use Ecommerce\Mail\Sender;
use Ecommerce\Transaction\Transaction;
use Log\Log;
use Mail\Mail\Recipient;

class SuccessMailSender extends Sender
{
	/**
	 * @var Transaction
	 */
	private $transaction;

	/**
	 * @var Customer
	 */
	private $customer;

	/**
	 * @param Transaction $transaction
	 * @return bool
	 */
	public function send(Transaction $transaction)
	{
		Log::debug('Sending success payment mail for ' . $transaction->getId()->toString());

		$this->transaction = $transaction;
		$this->customer    = $transaction->getCustomer();

		return $this->addToQueue();
	}

	/**
	 * @return Recipient
	 */
	protected function getRecipient()
	{
		return Recipient::create(
			$this->customer->getEmail(),
			$this->customer->getName()
		);
	}

	/**
	 * @return string
	 */
	protected function getContentTemplate()
	{
		return $this->getEcommerceMailConfig()['payment']['successful']['template'];
	}

	/**
	 * @return string
	 */
	protected function getSubject()
	{
		return $this->getEcommerceMailConfig()['payment']['successful']['subject'];
	}

	/**
	 * @return SuccessMailPlaceholderValues
	 */
	protected function getPlaceholderValues()
	{
		return SuccessMailPlaceholderValues::create()
			->setTransaction($this->transaction)
			->setCustomer($this->customer);
	}
}