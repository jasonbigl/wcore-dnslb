<?php

namespace Wcore\Dnslb\Aws\Route53;

final class AliasTarget
{
    public string $hostedZoneId;
    public string $dnsName;
    public bool $evaluateTargetHealth;

    public function __construct(string $hostedZoneId, string $dnsName, bool $evaluateTargetHealth)
    {
        $this->hostedZoneId = $hostedZoneId;
        $this->dnsName = RecordSet::normalizeName($dnsName);
        $this->evaluateTargetHealth = $evaluateTargetHealth;
    }
}
