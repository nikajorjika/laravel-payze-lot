<?php

namespace PayzeIO\LaravelPayze;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use PayzeIO\LaravelPayze\Concerns\ApiRequest;
use PayzeIO\LaravelPayze\Concerns\PayRequest;
use PayzeIO\LaravelPayze\Concerns\PayRequestAttributes;
use PayzeIO\LaravelPayze\Enums\Language;
use PayzeIO\LaravelPayze\Enums\Method;
use PayzeIO\LaravelPayze\Enums\Status;
use PayzeIO\LaravelPayze\Exceptions\ApiCredentialsException;
use PayzeIO\LaravelPayze\Exceptions\PaymentRequestException;
use PayzeIO\LaravelPayze\Models\PayzeCardToken;
use PayzeIO\LaravelPayze\Models\PayzeLog;
use PayzeIO\LaravelPayze\Models\PayzeTransaction;
use PayzeIO\LaravelPayze\Requests\AddCard;
use stdClass;

class Payze
{
    /**
     * Process pay request
     *
     * @param \PayzeIO\LaravelPayze\Concerns\PayRequest $request
     *
     * @return \Illuminate\Http\RedirectResponse|array
     * @throws \PayzeIO\LaravelPayze\Exceptions\PaymentRequestException
     * @throws \PayzeIO\LaravelPayze\Exceptions\ApiCredentialsException
     * @throws \Throwable
     */
    public function processPayment(PayRequest $request)
    {
        $method = $request->getMethod();
        $data = $request->toRequest();

        $this->log("Starting [$method] payment.", compact('method', 'data'));
        $response = $this->request($method, $data)['response'];

        $url = $response['transactionUrl'];
        $id = $response['transactionId'];

        throw_if(empty($id) || empty($url), new PaymentRequestException('Transaction ID is missing'));

        $this->log(
            "Transaction [$id] created",
            compact('id', 'response'),
            $transaction = $this->logTransaction($response ?? [], $request)
        );

        if ($method === Method::ADD_CARD && $request instanceof AddCard && filled($response['cardId'] ?? false)) {
            $this->saveCard($response['cardId'], $transaction, $request->getAssignedModel());
        }

        return $request->getRaw() ? $response : redirect($url);
    }

    /**
     * @param \PayzeIO\LaravelPayze\Concerns\ApiRequest $request
     * @param string|null $key
     *
     * @return \PayzeIO\LaravelPayze\Models\PayzeTransaction
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \PayzeIO\LaravelPayze\Exceptions\ApiCredentialsException
     * @throws \PayzeIO\LaravelPayze\Exceptions\PaymentRequestException
     * @throws \Throwable
     */
    public function processTransaction(ApiRequest $request, string $key = null): PayzeTransaction
    {
        $response = $this->process($request);

        if ($key) {
            $response = $response[$key];
        }

        return Payze::logTransaction($response, is_a($request, PayRequestAttributes::class) ? $request : null);
    }

    /**
     * @param \PayzeIO\LaravelPayze\Concerns\ApiRequest $request
     * @param bool $raw
     *
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \PayzeIO\LaravelPayze\Exceptions\ApiCredentialsException
     * @throws \PayzeIO\LaravelPayze\Exceptions\PaymentRequestException
     * @throws \Throwable
     */
    public function process(ApiRequest $request, bool $raw = false): array
    {
        $method = $request->getMethod();
        $data = $request->toRequest();

        $this->log("Sending [$method] request", $data);

        $response = $this->request($method, $data);

        $this->log("Received [$method] response", $response);

        return $raw ? $response : $response['response'];
    }

    /**
     * @param string $token
     * @param \PayzeIO\LaravelPayze\Models\PayzeTransaction $transaction
     * @param \Illuminate\Database\Eloquent\Model|null $model
     *
     * @return \PayzeIO\LaravelPayze\Models\PayzeCardToken
     */
    protected function saveCard(string $token, PayzeTransaction $transaction, ?Model $model = null): PayzeCardToken
    {
        return PayzeCardToken::create([
            'token' => $token,
            'transaction_id' => $transaction->id,
            'model_id' => optional($model)->id ?? $transaction->model_id,
            'model_type' => filled($model) ? Payze::modelType($model) : $transaction->model_type,
        ]);
    }

    /**
     * Create/update transaction entry in database
     *
     * @param array $data
     * @param \PayzeIO\LaravelPayze\Concerns\PayRequestAttributes|null $request
     *
     * @return \PayzeIO\LaravelPayze\Models\PayzeTransaction
     */
    public function logTransaction(array $data, PayRequestAttributes $request = null): PayzeTransaction
    {
        return tap(PayzeTransaction::firstOrNew([
            'transaction_id' => $data['transactionId'],
        ]), function (PayzeTransaction $transaction) use ($data, $request) {
            $transaction->fill(array_merge($request ? $request->toModel() : [], array_filter([
                'status' => $status = $data['status'] ?? Status::CREATED,
                'is_paid' => Status::isPaid($status),
                'is_completed' => Status::isCompleted($status),
                'commission' => $data['commission'] ?? null,
                'final_amount' => $data['finalAmount'] ?? null,
                'can_be_committed' => $data['canBeCommitted'] ?? $data['getCanBeCommitted'] ?? false,
                'refunded' => $data['refunded'] ?? null,
                'refundable' => $data['refundable'] ?? false,
                'card_mask' => $data['cardMask'] ?? null,
                'result_code' => $data['resultCode'] ?? null,
                'log' => $data['log'] ?? null,
            ])));

            foreach (['amount', 'currency'] as $field) {
                if (empty($transaction->$field) && !empty($data[$field])) {
                    $transaction->$field = $data[$field];
                }
            }

            if (empty($transaction->lang)) {
                $transaction->lang = Language::DEFAULT;
            }

            $transaction->save();

            if ($transaction->is_paid) {
                $card = $transaction->card;

                if ($card && !$card->active) {
                    $card->update([
                        'active' => true,
                        'default' => PayzeCardToken::where('model_type', $card->model_type)->where('model_id', $card->model_id)->where('default', true)->doesntExist(),
                        'card_mask' => $transaction->card_mask,
                        'cardholder' => $data['cardholder'] ?? null,
                        'brand' => $data['cardBrand'] ?? null,
                        'expiration_date' => $this->parseExpirationDate($data['expirationDate'] ?? null),
                    ]);
                }
            }
        });
    }

    protected function parseExpirationDate(?string $date): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        $month = substr($date, 0, 2);
        $year = substr($date, -2);

        // Some merchants may receive an expiration date in reversed order
        // Month is always less than a year, so we can easily detect it and reverse
        if (intval($month) > intval($year)) {
            [$year, $month] = [$month, $year];
        }

        return Carbon::createFromFormat('dmy', '01' . $month . $year)->endOfMonth();
    }

    /**
     * Create log entry in database
     *
     * @param string $message
     * @param array $data
     * @param \PayzeIO\LaravelPayze\Models\PayzeTransaction|null $transaction
     */
    public function log(string $message, array $data = [], PayzeTransaction $transaction = null): void
    {
        if (!config('payze.log')) {
            return;
        }

        PayzeLog::create([
            'transaction_id' => optional($transaction)->id,
            'message' => $message,
            'payload' => $data,
        ]);
    }

    /**
     * Send API request
     *
     * @param string $method
     * @param array $data
     *
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \PayzeIO\LaravelPayze\Exceptions\PaymentRequestException
     * @throws \PayzeIO\LaravelPayze\Exceptions\ApiCredentialsException
     * @throws \Throwable
     */
    public function request(string $method, array $data = []): array
    {
        $key = config('payze.api_key');
        $secret = config('payze.api_secret');

        throw_if(empty($key) || empty($secret), new ApiCredentialsException);

        try {
            $response = json_decode((new Client)->post('https://payze.io/api/v1', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'user-agent' => 'laravel-payze',
                ],
                'json' => [
                    'method' => $method,
                    'apiKey' => $key,
                    'apiSecret' => $secret,
                    'data' => $data ?: new stdClass,
                ],
                'verify' => config('payze.verify_ssl', true),
            ])->getBody()->getContents(), true);
        } catch (RequestException $exception) {
            throw new PaymentRequestException($exception->getMessage());
        }

        throw_unless(empty($response['response']['error']), new PaymentRequestException($response['response']['error'] ?? 'Error'));

        return $response;
    }

    /**
     * Get a model type regarding relation morph map
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return string
     */
    public static function modelType(Model $model): string
    {
        $morphMap = array_flip(Relation::morphMap());
        $class = get_class($model);

        return Arr::get($morphMap, $class, $class);
    }

    /**
     * @param $transaction
     *
     * @return string
     * @throws \PayzeIO\LaravelPayze\Exceptions\PaymentRequestException
     * @throws \Throwable
     */
    public static function parseTransaction($transaction): string
    {
        $isString = is_string($transaction) && filled($transaction);
        $isTransaction = is_a($transaction, PayzeTransaction::class);

        throw_unless($isString || $isTransaction, new PaymentRequestException('Please specify valid transaction'));

        if ($isTransaction) {
            return $transaction->transaction_id;
        }

        return $transaction;
    }

    /**
     * Define success and fail routes
     *
     * @param string $controller
     * @param string $successMethod
     * @param string $failMethod
     *
     * @return void
     */
    public static function routes(string $controller = \App\Http\Controllers\PayzeController::class, string $successMethod = 'success', string $failMethod = 'fail'): void
    {
        Route::prefix('payze')->name('payze.')->group(function () use ($controller, $successMethod, $failMethod) {
            Route::get('success', [$controller, $successMethod])->name('success');
            Route::get('fail', [$controller, $failMethod])->name('fail');
        });
    }
}
