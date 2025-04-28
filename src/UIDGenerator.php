<?php

namespace MicroUID;


use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Random\RandomException;
use RangeException;

class UIDGenerator
{
	private const CBASE = '123456789abcdefghjkmnpqrstuvwxyz';
	private const BASE_LEN = 32;
	private const EPOCH = 1735689600; // 01/01/2025
	
	/**
	 * @throws \DateMalformedStringException
	 * @throws RandomException
	 */
	public static function generate(int $length = 10, bool $withSeparator = true): string
	{
		if ($length < 8) {
			throw new InvalidArgumentException("UID muito curta para ser gerada.");
		}
		
		$timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - self::EPOCH;
		if ($timestamp < 0 || $timestamp >= self::BASE_LEN ** 6) {
			throw new RangeException('Data fora do intervalo representável.');
		}
		
		// Timestamp codificado e soma
		$ts = '';
		$sum = 0;
		$tmp = $timestamp;
		for ($i = 0; $i < 6; $i++) {
			$char = self::CBASE[$tmp % self::BASE_LEN];
			$ts = $char . $ts;
			$sum += strpos(self::CBASE, $char);
			$tmp = intdiv($tmp, self::BASE_LEN);
		}
		
		// Aleatórios
		$rs = '';
		for ($i = 0; $i < $length - 7; $i++) {
			$char = self::CBASE[random_int(0, self::BASE_LEN - 1)];
			$rs .= $char;
			$sum += strpos(self::CBASE, $char);
		}
		
		// Ofuscar timestamp
		$obs = '';
		foreach (str_split($ts) as $char) {
			$obs .= self::CBASE[(strpos(self::CBASE, $char) + $sum) % self::BASE_LEN];
		}
		
		// Inserir timestamp e checksum
		$mid = intdiv(strlen($rs), 2);
		$uid = substr($rs, 0, $mid) . $obs . substr($rs, $mid) . self::CBASE[$sum % self::BASE_LEN];
		
		return $withSeparator ? self::formatWithSeparator($uid) : $uid;
	}
	
	private static function formatWithSeparator(string $string): string
	{
		$len = strlen($string);
		for ($g4 = floor($len / 4); $g4 >= 0; $g4--) {
			$rest = $len - $g4 * 4;
			if ($rest % 3 === 0) {
				$tamanhos = array_merge(array_fill(0, intdiv($rest, 3) / 2, 3), array_fill(0, $g4, 4), array_fill(0, intdiv($rest, 3) - intdiv($rest, 3) / 2, 3));
				$pos = 0;
				$grupos = array_map(function ($t) use ($string, &$pos) {
					$part = substr($string, $pos, $t);
					$pos += $t;
					return $part;
				}, $tamanhos);
				return implode('-', $grupos);
			}
		}
		return $string;
	}
	
	/**
	 * @throws \DateMalformedStringException
	 */
	public static function validate(string $uid): DateTimeImmutable|false
	{
		$raw = str_replace('-', '', $uid);
		if (strlen($raw) < 7 || !preg_match('/^[1-9a-hjkmnp-z]+$/', $raw)) {
			return false;
		}
		
		$base = substr($raw, 0, -1);
		$checksum = substr($raw, -1);
		$mid = intdiv(strlen($base), 2) - 3;
		$stamp = substr($base, $mid, 6);
		
		// Desofuscar timestamp
		$sumOffset = strpos(self::CBASE, $checksum);
		$ts = '';
		foreach (str_split($stamp) as $char) {
			$ts .= self::CBASE[(strpos(self::CBASE, $char) - $sumOffset + self::BASE_LEN) % self::BASE_LEN];
		}
		$base = substr_replace($base, $ts, $mid, 6);
		
		// Recalcular soma e validar
		$sum = array_sum(array_map(fn($c) => strpos(self::CBASE, $c), str_split($base)));
		if ($checksum !== self::CBASE[$sum % self::BASE_LEN]) {
			return false;
		}
		
		// Reconstruir timestamp
		$n = 0;
		foreach (str_split($ts) as $c) {
			$n = $n * self::BASE_LEN + strpos(self::CBASE, $c);
		}
		
		$date = (new DateTimeImmutable('@' . ($n + self::EPOCH)))->setTimezone(new DateTimeZone('America/Manaus'));
		return ($date < new DateTimeImmutable()) ? $date : false;
	}
}