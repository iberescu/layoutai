<?php

namespace App\Exceptions;

/**
 * Thrown when the Gemini API returns 429 with a "spending cap" / "quota"
 * payload, meaning the project-level billing limit was hit. Distinct from
 * a generic Gemini failure because it requires admin action (raising the
 * cap in Google AI Studio) rather than a user retry.
 */
class GeminiQuotaExceededException extends \RuntimeException
{
}
