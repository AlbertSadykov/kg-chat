<?php

namespace App\Models;

class Settings
{
    public static function defaults(): array
    {
        return [
            'site_name' => 'Кезик • Анонимный чат', 'language' => 'ru', 'support_email' => '',
            'chat_enabled' => '1', 'entry_enabled' => '1', 'min_age' => '18', 'max_age' => '99',
            'message_length' => '1000', 'message_rate' => '12', 'ip_message_rate' => '80',
            'heartbeat_timeout' => '60', 'idle_timeout' => '600', 'queue_timeout' => '180',
            'transport' => 'poll', 'poll_seconds' => '3', 'long_poll_seconds' => '20',
            'log_messages' => '1', 'message_days' => '7', 'report_days' => '30',
            'identity_days' => '7', 'session_days' => '30', 'audit_days' => '90',
            'seo_description' => 'Анонимный чат 18+ с собеседниками из Кыргызстана.',
            'operator_name' => '', 'operator_address' => '', 'hosting_country' => '',
            'analytics_id' => '', 'rules_ru' => "Только для совершеннолетних (18+). Запрещены угрозы, травля, мошенничество, реклама, спам, сексуальная эксплуатация и любой контент с несовершеннолетними. Не отправляйте персональные данные, деньги или интимные материалы. Жалобы рассматриваются модераторами. При непосредственной опасности обращайтесь в экстренные службы.",
            'rules_ky' => "18 жаштан жогору адамдар үчүн гана. Коркутууга, куугунтуктоого, алдамчылыкка, жарнамага, спамга жана жашы жете электер катышкан сексуалдык мазмунга тыюу салынат. Жеке маалыматтарды, акчаны же интимдик материалдарды жөнөтпөңүз. Арыздарды модераторлор карайт. Кооптуу абалда шашылыш кызматтарга кайрылыңыз.",
        ];
    }

    public static function load(): array
    {
        $values = self::defaults();
        foreach (db()->all('SELECT name,value FROM ' . db()->table('settings')) as $row) {
            $values[$row['name']] = $row['value'];
        }
        return $values;
    }

    public static function put(string $name, string $value): void
    {
        db()->run('INSERT INTO ' . db()->table('settings') . '(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$name, $value]);
    }
}
