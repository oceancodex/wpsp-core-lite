<?php

namespace WPSPCORELITE\App\Http;

use UnexpectedValueException;

/** Host header không hợp lệ / không nằm trong trusted hosts (như Symfony). */
class SuspiciousOperationException extends UnexpectedValueException {

}
