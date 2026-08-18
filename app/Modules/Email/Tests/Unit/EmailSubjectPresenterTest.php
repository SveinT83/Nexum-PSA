<?php

namespace App\Modules\Email\Tests\Unit;

use App\Modules\Email\Support\EmailSubjectPresenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EmailSubjectPresenterTest extends TestCase
{
    #[Test]
    public function it_decodes_common_q_and_base64_encoded_words_without_changing_plain_unicode(): void
    {
        $cases = [
            '=?UTF-8?Q?Pr=C3=B8ve_bl=C3=A5b=C3=A6r?=' => 'Prøve blåbær',
            '=?UTF-8?B?UHLDuHZlIGJsw6Viw6Zy?=' => 'Prøve blåbær',
            '=?ISO-8859-1?Q?bl=E5b=E6r?=' => 'blåbær',
            '=?Windows-1252?Q?Pris_=80_10?=' => 'Pris € 10',
            'Re: =?UTF-8?Q?Pr=C3=B8ve?=' => 'Re: Prøve',
            '=?UTF-8?Q?Hei?= =?UTF-8?Q?_verden?=' => 'Hei verden',
            "=?UTF-8?Q?Foldet?=\r\n\t=?UTF-8?Q?_emne?=" => 'Foldet emne',
            'Vanlig norsk: Prøve blåbær 😊' => 'Vanlig norsk: Prøve blåbær 😊',
        ];

        foreach ($cases as $raw => $expected) {
            $presented = EmailSubjectPresenter::present($raw);

            $this->assertSame($expected, $presented);
            $this->assertSame($expected, EmailSubjectPresenter::present($presented));
        }
    }

    #[Test]
    public function it_salvages_the_truncated_provider_subject_without_exposing_invalid_utf8(): void
    {
        $raw = '=?utf-8?Q?Fwd=3A_DEKKSPERTEN_DA_=28936529364=29_har_f=C3=';

        $this->assertSame(
            'Fwd: DEKKSPERTEN DA (936529364) har f',
            EmailSubjectPresenter::present($raw),
        );
        $this->assertTrue(mb_check_encoding(EmailSubjectPresenter::present($raw), 'UTF-8'));
    }

    #[Test]
    public function it_sanitizes_header_controls_bounds_output_and_keeps_html_as_text(): void
    {
        $this->assertNull(EmailSubjectPresenter::present(null));
        $this->assertNull(EmailSubjectPresenter::present("\0\r\n\t"));
        $this->assertSame(
            'Hello Bcc: victim@example.test world',
            EmailSubjectPresenter::present("Hello\r\nBcc: victim@example.test\0\x07 world"),
        );
        $this->assertSame(
            '<script>alert(1)</script>',
            EmailSubjectPresenter::present('<script>alert(1)</script>'),
        );
        $this->assertSame(512, mb_strlen(EmailSubjectPresenter::present(str_repeat('æ', 700))));
    }

    #[Test]
    public function unsupported_or_invalid_encoded_words_fail_closed_to_safe_text(): void
    {
        $unknown = '=?X-UNKNOWN?Q?Hello_=E5?=';
        $invalidBase64 = '=?UTF-8?B?%%%?=';
        $nested = '=?UTF-8?B?'.base64_encode('=?UTF-8?Q?Hi?=').'?=';
        $placeholderCollision = "\x1A0\x1A =?UTF-8?Q?Hello?=";

        $this->assertSame($unknown, EmailSubjectPresenter::present($unknown));
        $this->assertSame($invalidBase64, EmailSubjectPresenter::present($invalidBase64));
        $this->assertSame($nested, EmailSubjectPresenter::present($nested));
        $this->assertSame($nested, EmailSubjectPresenter::present(EmailSubjectPresenter::present($nested)));
        $this->assertSame('0 Hello', EmailSubjectPresenter::present($placeholderCollision));
        $this->assertSame('0 Hello', EmailSubjectPresenter::present(EmailSubjectPresenter::present($placeholderCollision)));
        $this->assertTrue(mb_check_encoding(EmailSubjectPresenter::present("Broken \xC3"), 'UTF-8'));
    }
}
