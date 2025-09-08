<?php

namespace Karabaman\Gateway;

use Karabaman\Gateway\Parsian\Parsian;
use Karabaman\Gateway\Paypal\Paypal;
use Karabaman\Gateway\Sadad\Sadad;
use Karabaman\Gateway\Mellat\Mellat;
use Karabaman\Gateway\Pasargad\Pasargad;
use Karabaman\Gateway\Saman\Saman;
use Karabaman\Gateway\Asanpardakht\Asanpardakht;
use Karabaman\Gateway\Zarinpal\Zarinpal;
use Karabaman\Gateway\Payir\Payir;
use Karabaman\Gateway\Exceptions\RetryException;
use Karabaman\Gateway\Exceptions\PortNotFoundException;
use Karabaman\Gateway\Exceptions\InvalidRequestException;
use Karabaman\Gateway\Exceptions\NotFoundTransactionException;
use Illuminate\Support\Facades\DB;

class GatewayResolver
{

    protected $request;

    /**
     * @var array Config
     */
    public $config;

    /**
     * Keep current port driver
     *
     * @var Mellat|Saman|Sadad|Zarinpal|Payir|Parsian
     */
    protected $port;

    /**
     * Gateway constructor.
     * @param array $config
     * @param null $port
     * @throws PortNotFoundException
     */
    public function __construct($config = [], $port = null)
    {
        $this->config = $config ?: app('config')['gateway'];
        $this->request = app('request');

        if ($tz = $this->getTimeZone()) {
            date_default_timezone_set($tz);
        }

        if (!is_null($port)) {
            $this->make($port);
        }
    }

    /**
     * Get supported ports
     *
     * @return array
     */
    public function getSupportedPorts()
    {
        return [
            Enum::MELLAT,
            Enum::SADAD,
            Enum::ZARINPAL,
            Enum::PARSIAN,
            Enum::PASARGAD,
            Enum::SAMAN,
            Enum::PAYPAL,
            Enum::ASANPARDAKHT,
            Enum::PAYIR
        ];
    }

    /**
     * Call methods of current driver
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {

        // calling by this way ( Gateway::mellat()->.. , Gateway::parsian()->.. )
        if (in_array(strtoupper($name), $this->getSupportedPorts())) {
            return $this->make($name);
        }

        return call_user_func_array([$this->port, $name], $arguments);
    }

    /**
     * Gets query builder from you transactions table
     * @return mixed
     */
    function getTable()
    {
        return DB::table($this->config['table']);
    }

    /**
     * Callback
     *
     * @return $this->port
     *
     * @throws InvalidRequestException
     * @throws NotFoundTransactionException
     * @throws PortNotFoundException
     * @throws RetryException
     */
    public function verify()
    {
        if (!$this->request->has('transaction_id') && !$this->request->has('iN')) {
            throw new InvalidRequestException;
        }
        if ($this->request->has('transaction_id')) {
            $id = $this->request->get('transaction_id');
        } else {
            $id = $this->request->get('iN');
        }

        $transaction = $this->getTable()->whereId($id)->first();

        if (!$transaction) {
            throw new NotFoundTransactionException;
        }

        if (in_array($transaction->status, [Enum::TRANSACTION_SUCCEED, Enum::TRANSACTION_FAILED])) {
            throw new RetryException;
        }

        $this->make($transaction->port);

        return $this->port->verify($transaction);
    }


    /**
     * Create new object from port class
     *
     * @param int $port
     * @throws PortNotFoundException
     */
    function make($port)
    {
        if ($port instanceof Mellat) {
            $name = Enum::MELLAT;
        } elseif ($port instanceof Parsian) {
            $name = Enum::PARSIAN;
        } elseif ($port instanceof Saman) {
            $name = Enum::SAMAN;
        } elseif ($port instanceof Zarinpal) {
            $name = Enum::ZARINPAL;
        } elseif ($port instanceof Sadad) {
            $name = Enum::SADAD;
        } elseif ($port instanceof Asanpardakht) {
            $name = Enum::ASANPARDAKHT;
        } elseif ($port instanceof Paypal) {
            $name = Enum::PAYPAL;
        } elseif ($port instanceof Payir) {
            $name = Enum::PAYIR;
        } elseif (in_array(strtoupper($port), $this->getSupportedPorts())) {
            $port = ucfirst(strtolower($port));
            $name = strtoupper($port);
            $class = __NAMESPACE__ . '\\' . $port . '\\' . $port;
            $port = new $class;
        } else {
            throw new PortNotFoundException;
        }

        $this->port = $port;
        $this->port->setConfig($this->config); // injects config
        $this->port->setPortName($name); // injects config
        $this->port->boot();

        return $this;
    }

    private function getTimeZone()
    {
        return $this->config['gateway']['timezone'] ?? null;
    }

    /**
     * @param array $config
     * @return $this
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
        return $this;
    }
}
