<?php

function generateOTP(): string
{
    return strval(random_int(100000, 999999));
}

function sendOTPEmail(string $toEmail, string $username, string $otp): bool
{
    return false;
}
