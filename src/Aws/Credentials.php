<?php

namespace Wcore\Dnslb\Aws;

final class Credentials
{
    public string $accessKeyId;
    public string $secretAccessKey;

    public function __construct(string $accessKeyId, string $secretAccessKey)
    {
        $this->accessKeyId = $accessKeyId;
        $this->secretAccessKey = $secretAccessKey;
    }
}
