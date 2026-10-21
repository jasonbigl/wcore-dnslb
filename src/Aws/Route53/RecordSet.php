<?php

namespace Wcore\Dnslb\Aws\Route53;

/**
 * Route 53 资源记录集。只覆盖本项目用到的字段：
 * 简单 / 加权 / 延迟 / 多值回答路由，普通记录或 Alias。
 */
final class RecordSet
{
    /** 不带结尾点号的小写域名 */
    public string $name;
    public string $type;
    public ?string $setIdentifier = null;
    public ?int $weight = null;
    public ?string $region = null;
    public bool $multiValueAnswer = false;
    public ?int $ttl = null;
    /** @var string[] */
    public array $values = [];
    public ?AliasTarget $aliasTarget = null;
    public ?string $healthCheckId = null;

    public function __construct(string $name, string $type)
    {
        $this->name = self::normalizeName($name);
        $this->type = strtoupper($type);
    }

    /**
     * @param string[] $values
     */
    public static function multiValue(string $name, string $type, string $setIdentifier, int $ttl, array $values): self
    {
        $record = new self($name, $type);
        $record->setIdentifier = $setIdentifier;
        $record->multiValueAnswer = true;
        $record->ttl = $ttl;
        $record->values = $values;
        return $record;
    }

    /**
     * @param string[] $values
     */
    public static function weighted(string $name, string $type, string $setIdentifier, int $weight, int $ttl, array $values): self
    {
        $record = new self($name, $type);
        $record->setIdentifier = $setIdentifier;
        $record->weight = $weight;
        $record->ttl = $ttl;
        $record->values = $values;
        return $record;
    }

    public static function latencyAlias(string $name, string $type, string $setIdentifier, string $region, AliasTarget $target): self
    {
        $record = new self($name, $type);
        $record->setIdentifier = $setIdentifier;
        $record->region = $region;
        $record->aliasTarget = $target;
        return $record;
    }

    /**
     * 统一域名格式：小写、去掉结尾点号、还原 Route 53 的八进制转义（如 \052 → *）。
     */
    public static function normalizeName(string $name): string
    {
        $name = preg_replace_callback('/\\\\([0-7]{3})/', static function (array $m): string {
            return chr((int) octdec($m[1]));
        }, $name);
        return strtolower(rtrim($name, '.'));
    }

    /**
     * 转成 Route 53 的转义格式（* → \052），用于查询参数等需要精确名称的地方。
     */
    public static function escapeName(string $name): string
    {
        return str_replace('*', '\\052', self::normalizeName($name));
    }

    /**
     * IP 地址统一成标准写法（IPv6 有多种等价写法），其他值原样返回。
     */
    public static function normalizeValue(string $value): string
    {
        $packed = @inet_pton($value);
        return $packed === false ? $value : (string) inet_ntop($packed);
    }

    /**
     * 同一个托管区域内唯一标识一条记录：名称 + 类型 + SetIdentifier。
     */
    public function key(): string
    {
        return $this->name . '|' . $this->type . '|' . ($this->setIdentifier ?? '');
    }

    /**
     * 内容是否完全相同（用于判断是否需要更新）。
     */
    public function sameAs(RecordSet $other): bool
    {
        $values = array_map([self::class, 'normalizeValue'], $this->values);
        $otherValues = array_map([self::class, 'normalizeValue'], $other->values);
        sort($values);
        sort($otherValues);

        $alias = $this->aliasTarget;
        $otherAlias = $other->aliasTarget;
        $sameAlias = ($alias === null && $otherAlias === null)
            || ($alias !== null && $otherAlias !== null
                && $alias->hostedZoneId === $otherAlias->hostedZoneId
                && $alias->dnsName === $otherAlias->dnsName
                && $alias->evaluateTargetHealth === $otherAlias->evaluateTargetHealth);

        return $this->key() === $other->key()
            && $this->weight === $other->weight
            && $this->region === $other->region
            && $this->multiValueAnswer === $other->multiValueAnswer
            && $this->ttl === $other->ttl
            && $values === $otherValues
            && $sameAlias
            && $this->healthCheckId === $other->healthCheckId;
    }

    public static function fromXml(\SimpleXMLElement $xml): self
    {
        $record = new self((string) $xml->Name, (string) $xml->Type);
        if (isset($xml->SetIdentifier)) {
            $record->setIdentifier = (string) $xml->SetIdentifier;
        }
        if (isset($xml->Weight)) {
            $record->weight = (int) $xml->Weight;
        }
        if (isset($xml->Region)) {
            $record->region = (string) $xml->Region;
        }
        if (isset($xml->MultiValueAnswer)) {
            $record->multiValueAnswer = (string) $xml->MultiValueAnswer === 'true';
        }
        if (isset($xml->TTL)) {
            $record->ttl = (int) $xml->TTL;
        }
        if (isset($xml->ResourceRecords)) {
            foreach ($xml->ResourceRecords->ResourceRecord as $item) {
                $record->values[] = (string) $item->Value;
            }
        }
        if (isset($xml->AliasTarget)) {
            $record->aliasTarget = new AliasTarget(
                (string) $xml->AliasTarget->HostedZoneId,
                (string) $xml->AliasTarget->DNSName,
                (string) $xml->AliasTarget->EvaluateTargetHealth === 'true'
            );
        }
        if (isset($xml->HealthCheckId)) {
            $record->healthCheckId = (string) $xml->HealthCheckId;
        }
        return $record;
    }

    /**
     * 生成 <ResourceRecordSet> 元素。子元素顺序必须与 Route 53 的 XML Schema 一致。
     */
    public function toXml(): string
    {
        $xml = '<ResourceRecordSet>';
        $xml .= self::element('Name', $this->name);
        $xml .= self::element('Type', $this->type);
        if ($this->setIdentifier !== null) {
            $xml .= self::element('SetIdentifier', $this->setIdentifier);
        }
        if ($this->weight !== null) {
            $xml .= self::element('Weight', (string) $this->weight);
        }
        if ($this->region !== null) {
            $xml .= self::element('Region', $this->region);
        }
        if ($this->multiValueAnswer) {
            $xml .= self::element('MultiValueAnswer', 'true');
        }
        if ($this->ttl !== null) {
            $xml .= self::element('TTL', (string) $this->ttl);
        }
        if ($this->values !== []) {
            $xml .= '<ResourceRecords>';
            foreach ($this->values as $value) {
                $xml .= '<ResourceRecord>' . self::element('Value', $value) . '</ResourceRecord>';
            }
            $xml .= '</ResourceRecords>';
        }
        if ($this->aliasTarget !== null) {
            $xml .= '<AliasTarget>'
                . self::element('HostedZoneId', $this->aliasTarget->hostedZoneId)
                . self::element('DNSName', $this->aliasTarget->dnsName)
                . self::element('EvaluateTargetHealth', $this->aliasTarget->evaluateTargetHealth ? 'true' : 'false')
                . '</AliasTarget>';
        }
        if ($this->healthCheckId !== null) {
            $xml .= self::element('HealthCheckId', $this->healthCheckId);
        }
        return $xml . '</ResourceRecordSet>';
    }

    private static function element(string $name, string $value): string
    {
        return "<{$name}>" . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</{$name}>";
    }
}
