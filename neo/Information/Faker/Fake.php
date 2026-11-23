<?php

declare(strict_types=1);

namespace Neo\Information\Faker;

use Closure;
use DateTimeInterface;
use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Information\Datetime\Chronos;
use Neo\Information\Faker\Modifiers\OptionalGenerator;
use Neo\Information\Faker\Modifiers\UniqueGenerator;
use Neo\Information\Faker\Modifiers\ValidGenerator;

/**
 * Control
 * @method static Faker addProvider(Provider|string $provider)
 * @method static Faker macro(string $name, Closure $callback)
 * @method static Faker seed(?int $seed = null)
 * @method static Faker setLocale(string $code)
 * @method static string getLocale()
 * @method static UniqueGenerator unique(bool $reset = false, int $maxRetries = 10000)
 * @method static OptionalGenerator optional(float $weight = 0.5, mixed $default = null)
 * @method static ValidGenerator valid(?Closure $validator = null, int $maxRetries = 10000)
 *
 * Numbers, text, picking
 * @method static int numberBetween(int $min = 0, int $max = 2147483647)
 * @method static int randomDigit()
 * @method static int randomDigitNotZero()
 * @method static int randomNumber(?int $digits = null, bool $strict = false)
 * @method static float randomFloat(?int $decimals = null, float $min = 0.0, ?float $max = null)
 * @method static string randomLetter()
 * @method static mixed randomElement(array $array = ['a', 'b', 'c'])
 * @method static array randomElements(array $array = ['a', 'b', 'c'], int $count = 1, bool $allowDuplicates = false)
 * @method static array shuffleArray(array $array = [])
 * @method static string shuffleString(string $string = '')
 * @method static string numerify(string $format = '###')
 * @method static string lexify(string $format = '????')
 * @method static string bothify(string $format = '## ??')
 * @method static bool boolean(int $chanceOfGettingTrue = 50)
 * @method static string word()
 * @method static array|string words(int $count = 3, bool $asText = false)
 * @method static string sentence(int $words = 6, bool $variable = true)
 * @method static array|string sentences(int $count = 3, bool $asText = false)
 * @method static string paragraph(int $sentences = 3, bool $variable = true)
 * @method static array|string paragraphs(int $count = 3, bool $asText = false)
 * @method static string text(int $maxChars = 200)
 *
 * People and companies
 * @method static string gender()
 * @method static string name(?string $gender = null)
 * @method static string firstName(?string $gender = null)
 * @method static string firstNameMale()
 * @method static string firstNameFemale()
 * @method static string lastName(?string $gender = null)
 * @method static string middleName(?string $gender = null)
 * @method static string prefix(?string $gender = null)
 * @method static string suffix()
 * @method static string company()
 * @method static string companySuffix()
 * @method static string jobTitle()
 *
 * Internet
 * @method static string email()
 * @method static string safeEmail()
 * @method static string freeEmail()
 * @method static string companyEmail()
 * @method static string userName()
 * @method static string password(int $minLength = 6, int $maxLength = 20)
 * @method static string domainWord()
 * @method static string domainName()
 * @method static string tld()
 * @method static string url()
 * @method static string slug(int $words = 4)
 * @method static string ipv4()
 * @method static string localIpv4()
 * @method static string ipv6()
 * @method static string macAddress()
 * @method static string userAgent()
 *
 * Places and phones
 * @method static string country()
 * @method static string countryCode()
 * @method static string state()
 * @method static string city()
 * @method static string streetName()
 * @method static string buildingNumber()
 * @method static string streetAddress()
 * @method static string postcode()
 * @method static string address()
 * @method static float latitude(float $min = -90.0, float $max = 90.0)
 * @method static float longitude(float $min = -180.0, float $max = 180.0)
 * @method static array coordinates()
 * @method static string phoneNumber()
 * @method static string e164PhoneNumber()
 *
 * Date and time
 * @method static Chronos dateTime(DateTimeInterface|int|string $max = 'now', ?string $timezone = null)
 * @method static Chronos dateTimeBetween(DateTimeInterface|int|string $start = '-30 years', DateTimeInterface|int|string $end = 'now', ?string $timezone = null)
 * @method static Chronos dateTimeThisYear(DateTimeInterface|int|string $max = 'now', ?string $timezone = null)
 * @method static Chronos dateTimeThisMonth(DateTimeInterface|int|string $max = 'now', ?string $timezone = null)
 * @method static string date(string $format = 'Y-m-d', DateTimeInterface|int|string $max = 'now')
 * @method static string time(string $format = 'H:i:s')
 * @method static int unixTime(DateTimeInterface|int|string $max = 'now')
 * @method static string iso8601(DateTimeInterface|int|string $max = 'now')
 * @method static string year(DateTimeInterface|int|string $max = 'now')
 * @method static string month()
 * @method static string dayOfMonth()
 * @method static string monthName()
 * @method static string dayOfWeek()
 * @method static string timezone()
 *
 * Colors, ids, money, files, auth
 * @method static string hexColor()
 * @method static array rgbColorAsArray()
 * @method static string rgbColor()
 * @method static string rgbCssColor()
 * @method static string hslColor()
 * @method static string colorName()
 * @method static string uuid()
 * @method static string creditCardType()
 * @method static string creditCardNumber(?string $type = null, bool $formatted = false, string $separator = '-')
 * @method static Chronos creditCardExpirationDate()
 * @method static string creditCardExpirationDateString(string $format = 'm/y')
 * @method static string creditCardCvv()
 * @method static string iban(?string $countryCode = null)
 * @method static string currencyCode()
 * @method static string mimeType()
 * @method static string fileExtension()
 * @method static string fileName(?string $extension = null)
 * @method static string imageUrl(int $width = 640, int $height = 480)
 * @method static string hashedPassword(string $plain = 'password')
 * @method static string rememberToken()
 * @method static string token(int $length = 64)
 *
 * @see Faker
 */
#[FacadeOf(Faker::class)]
final class Fake extends Facade {}
