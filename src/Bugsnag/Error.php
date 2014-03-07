<?php

class Bugsnag_Error
{
    private static $VALID_SEVERITIES = array(
        'fatal',
        'error',
        'warning',
        'info'
    );

    public $name;
    public $message;
    public $severity = "error";
    public $stacktrace;
    public $metaData = array();
    public $config;
    public $diagnostics;
    public $code;

    /**
     * If set by the consuming code this callable will
     * be used to modify the stack trace before it is
     * sent to bugsnag. This is useful for removing any wrapping
     * code from the trace.
     *
     * @var callable|null
     */
    private $stackModifier;

    // Static error creation methods, to ensure that Error object is always complete
    public static function fromPHPError(Bugsnag_Configuration $config, Bugsnag_Diagnostics $diagnostics, $code, $message, $file, $line, $fatal=false)
    {
        $error = new Bugsnag_Error($config, $diagnostics);
        $error->setPHPError($code, $message, $file, $line, $fatal);

        return $error;
    }

    public static function fromPHPException(Bugsnag_Configuration $config, Bugsnag_Diagnostics $diagnostics, Exception $exception)
    {
        $error = new Bugsnag_Error($config, $diagnostics);
        $error->setPHPException($exception);

        return $error;
    }

    public static function fromNamedError(Bugsnag_Configuration $config, Bugsnag_Diagnostics $diagnostics, $name, $message=NULL)
    {
        $error = new Bugsnag_Error($config, $diagnostics);
        $trace = Bugsnag_Stacktrace::generate($config);
        $error->setName($name)
              ->setMessage($message)
              ->setStacktrace($trace);

        return $error;
    }

    // Private constructor (for use only by the static methods above)
    private function __construct(Bugsnag_Configuration $config, Bugsnag_Diagnostics $diagnostics)
    {
        $this->config = $config;
        $this->diagnostics = $diagnostics;
    }

    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    public function setMessage($message)
    {
        $this->message = $message;

        return $this;
    }

    public function setStacktrace($stacktrace)
    {
        $this->stacktrace = $stacktrace;
        return $this;
    }

    public function setCode($code)
    {
        $this->code = $code;

        return $this;
    }

    public function setSeverity($severity)
    {
        if (!is_null($severity)) {
            if (in_array($severity, Bugsnag_Error::$VALID_SEVERITIES)) {
                $this->severity = $severity;
            } else {
                error_log('Bugsnag Warning: Tried to set error severity to '. $severity .' which is not allowed.');
            }
        }

        return $this;
    }

    public function setPHPException(Exception $exception)
    {
        $trace = Bugsnag_Stacktrace::fromBacktrace(
            $this->config,
            $exception->getTrace(),
            $exception->getFile(),
            $exception->getLine()
        );
        $this->setName(get_class($exception))
             ->setMessage($exception->getMessage())
             ->setStacktrace($trace);

        return $this;
    }

    public function setPHPError($code, $message, $file, $line, $fatal=false)
    {
        if ($fatal) {
            // Generating stacktrace for PHP fatal errors is not possible,
            // since this code executes when the PHP process shuts down,
            // rather than at the time of the crash.
            //
            // In these situations, we generate a "stacktrace" containing only
            // the line and file number where the crash occurred.
            $stacktrace = Bugsnag_Stacktrace::fromFrame($this->config, $file, $line);
        } else {
            $stacktrace = Bugsnag_Stacktrace::generate($this->config);
        }

        $this->setName(Bugsnag_ErrorTypes::getName($code))
             ->setMessage($message)
             ->setSeverity(Bugsnag_ErrorTypes::getSeverity($code))
             ->setStacktrace($stacktrace)
             ->setCode($code);

        return $this;
    }

    public function setMetaData($metaData)
    {
        if (is_array($metaData)) {
            $this->metaData = array_merge_recursive($this->metaData, $metaData);
        }

        return $this;
    }

    public function shouldIgnore()
    {
        // Check if we should ignore errors of this type
        if (isset($this->code)) {
            if (isset($this->config->errorReportingLevel)) {
                return !($this->config->errorReportingLevel & $this->code);
            } else {
                return !(error_reporting() & $this->code);
            }
        }

        return false;
    }

    public function toArray()
    {
        return array(
            'app' => $this->diagnostics->getAppData(),
            'device' => $this->diagnostics->getDeviceData(),
            'user' => $this->diagnostics->getUser(),
            'context' => $this->diagnostics->getContext(),
            'severity' => $this->severity,
            'exceptions' => array(array(
                'errorClass' => $this->name,
                'message' => $this->message,
                'stacktrace' => $this->getModifiedTrace($this->stacktrace)->toArray()
            )),
            'metaData' => $this->applyFilters($this->metaData)
        );
    }

    /**
     * Allows calling code to set a function that will be passed the
     * trace before it's sent to bugsnag. The function should return
     * an appropriately modified stack trace.
     *
     * @param callable $function
     * @throws InvalidArgumentException
     */
    public function setStackModifierFunction($function)
    {
        if (!is_callable($function)) {
            throw new \InvalidArgumentException('$callback must be callable');
        }
        $this->stackModifier = $function;
    }

    /**
     * @param \Bugsnag_Stacktrace $trace
     * @return \Bugsnag_Stacktrace
     */
    private function getModifiedTrace($trace)
    {
        $modifyCallable = $this->stackModifier;
        if (is_callable($modifyCallable)) {
            $trace = $modifyCallable($trace);
        }
        return $trace;
    }

    private function applyFilters($metaData)
    {
        if (!empty($this->config->filters)) {
            $cleanMetaData = array();

            foreach ($metaData as $key => $value) {
                $shouldFilter = false;
                foreach ($this->config->filters as $filter) {
                    if (strpos($key, $filter) !== false) {
                        $shouldFilter = true;
                        break;
                    }
                }

                if ($shouldFilter) {
                    $cleanMetaData[$key] = '[FILTERED]';
                } else {
                    if (is_array($value)) {
                        $cleanMetaData[$key] = $this->applyFilters($value);
                    } else {
                        $cleanMetaData[$key] = $value;
                    }
                }
            }

            return $cleanMetaData;
        } else {
            return $metaData;
        }
    }
}
