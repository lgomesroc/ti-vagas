<?php

namespace App\Services\Network;

/**
 * Identificador derivado do IP para chaves de cache e logs.
 *
 * O IP é dado pessoal: nenhum registro desta funcionalidade o guarda em
 * texto claro. O HMAC usa a chave da aplicação, então não pode ser revertido
 * por quem tenha acesso apenas ao cache ou aos logs.
 */
final class IpFingerprint
{
    public static function of(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * Versão curta para logs.
     */
    public static function short(string $ip): string
    {
        return substr(self::of($ip), 0, 16);
    }
}
