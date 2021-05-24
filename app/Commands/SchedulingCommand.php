<?php

namespace App\Commands;

use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Carbon\Factory;
use LaravelZero\Framework\Commands\Command;

class SchedulingCommand extends Command
{
    const BREAK_LENGTH_MINUTES = 20;
    const TALK_LENGTH_MINUTES = 60;
    const BREAK_SLOT_NAME = 'Break';
    const EXIT_SLOT_NAME = 'Closing Remarks & No Plans To Merge After Party';

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'scheduling';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Display Alpine Day scheduling';

    /**
     * The conference schedule.
     *
     * @var array
     */
    protected $scheduling = [
        '09:00' => 'Opening Remarks',
        '09:10' => 'The State Of Alpine <fg=#6C7280>by CALEB PORZIO</>',
        '10:00' => 'Tips for real-world AlpineJS <fg=#6C7280>by HUGO DI FRANCESCO</>',
        '10:20' => 'Building a Better Modal <fg=#6C7280>by AUSTEN CAMERON</>',
        '10:40' => 'Micro Interactions using AlpineJS <fg=#6C7280>by SHRUTI BALASA</>',
        '11:00' => self::BREAK_SLOT_NAME,
        '11:20' => 'How to keep your tech stack simple... <fg=#6C7280>by JUSTIN JACKSON & JON BUDA</>',
        '11:40' => 'How To Carve A Spoon (Literally) <fg=#6C7280>by JESSE SCHUTT</>',
        '12:00' => 'From Vue to Alpine: How & Why <fg=#6C7280>by MATT STAUFFER</>',
        '12:20' => self::BREAK_SLOT_NAME,
        '12:30' => 'Live Pairing Session: Building Alpine V3 From Scratch <fg=#6C7280>by ADAM WATHAN</>',
        '13:15' => 'The Future Of Alpine <fg=#6C7280>by CALEB PORZIO</>',
        '15:00' => 'Closing Remarks & No Plans To Merge After Party',
    ];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $userTimeZone = $this->getTimeZone();

        $this->line('');
        $this->line("    <options=bold,reverse;fg=cyan> ALPINE DAY 2021 </>");
        $this->line('');

        $this->line('    Your timezone: ' . $userTimeZone . '.');

        $startsAt = '2021-06-10 10:00';
        $endsAt = '2021-06-10 19:00';

        $daysLeft = Carbon::parse($startsAt, 'America/New_York')
                ->setTimezone($userTimeZone)
                ->diffInDays(now(), false);

        $hoursLeft = Carbon::parse($startsAt, 'America/New_York')
                ->setTimezone($userTimeZone)
                ->diffInHours(now(), false);

        $minutesLeft = Carbon::parse($startsAt, 'America/New_York')
                ->setTimezone($userTimeZone)
                ->diffInMinutes(now(), false);

        if ($daysLeft < 0) {
            $daysLeft = abs($daysLeft);
            $this->line("    Event status : Starts in $daysLeft days.");
        } elseif ($hoursLeft < 0) {
            $hoursLeft = abs($hoursLeft);
            $this->line("    Event status : Starts in $hoursLeft hours.");
        } elseif ($minutesLeft < 0) {
            $minutesLeft = abs($minutesLeft);
            $this->line("    Event status : Starts in $minutesLeft minutes.");
        } elseif (Carbon::parse($endsAt, 'America/New_York')->setTimezone($userTimeZone)->isPast()) {
            $this->line("    Event status : Event has ended. See you next time!");
        } else {
            $this->line("    Event status : Already started.");
        }

        $showedHappeningNowOnce = false;

        $this->line('');
        collect($this->scheduling)->each(function ($talk, $schedule) use ($userTimeZone, &$showedHappeningNowOnce) {
            $dateTime = Carbon::parse("2021-06-10 $schedule:00", 'America/New_York')
                ->setTimezone($userTimeZone);

            $lineOptions = 'bold';

            if (! $showedHappeningNowOnce && $this->happeningNow($dateTime, $userTimeZone, $talk)) {
                $lineOptions = 'bold,reverse;fg=yellow';
                $showedHappeningNowOnce = true;
            }

            $this->line("    <options={$lineOptions}>{$dateTime->format('h:i A')}</> - $talk");
        });

        $this->line('');
        $this->line('    <fg=magenta;options=bold>Join the community:</> ');
        $this->line('    Discord : https://discord.gg/6FkTRwxA68.');
        $this->line('');
    }

    /**
     * Returns the user's timezone.
     *
     * @return string
     */
    public function getTimeZone()
    {
        $disk = Storage::disk('local');

        if (! $disk->exists('.laravel-schedule')) {
            $timeZone = $this->getSystemTimeZone($exitCode);

            if ($exitCode > 0 || $timeZone === '') {
                abort(500, 'Unable to retrieve timezone');
            }

            $disk->put('.laravel-schedule', $timeZone);
        }

        return $disk->get('.laravel-schedule');
    }

    /**
     * @param &$exitCode
     * @return string
     */
    private function getSystemTimeZone(&$exitCode): string
    {
        switch (true) {
            case Str::contains(php_uname('s'), 'Darwin'):
                $this->line('Please enter your "sudo" password so we can retrieve your timezone:');
                return ltrim(exec('sudo systemsetup -gettimezone', $_, $exitCode), 'Time Zone: ');
            case Str::contains(php_uname('s'), 'Linux'):
                if (file_exists('/etc/timezone')) {
                    return ltrim(exec('cat /etc/timezone', $_, $exitCode));
                }

                return exec('date +%Z', $_, $exitCode);
            case Str::contains(php_uname('s'), 'Windows'):
                return ltrim($this->getIanaTimeZoneFromWindowsIdentifier(exec('tzutil /g', $_, $exitCode)));
            default:
                abort(401, 'Your OS is not supported at this time.');
        }
    }

    /**
     * Returns an IANA time zone string from a Microsoft Windows time zone identifier
     *  `./data/windowsZones.json` file content from windowsZones.xml
     *  https://github.com/unicode-org/cldr/blob/master/common/supplemental/windowsZones.xml
     *
     * @param string $timeZoneId Windows time zone identifier (i.e. 'E. South America Standard Time')
     * @return string
     */
    private function getIanaTimeZoneFromWindowsIdentifier($timeZoneId)
    {
        $json =Storage::disk('windowsconfig')->get('windowsZones.json');
        $zones = collect(json_decode($json));

        $timeZone = $zones->firstWhere('windowsIdentifier', '=', $timeZoneId);

        abort_if(is_null($timeZone), 401, 'Windows time zone not found.');

        return head($timeZone->iana);
    }

    private function happeningNow(Carbon $dateTime, string $userTimeZone, string $talk): bool
    {
        if ($talk === self::EXIT_SLOT_NAME) {
            return false;
        }

        $length = $talk === self::BREAK_SLOT_NAME
            ? self::BREAK_LENGTH_MINUTES
            : self::TALK_LENGTH_MINUTES;

        return Carbon::now($userTimeZone)->between(
            $dateTime,
            $dateTime->copy()->addMinutes($length)
        );
    }
}
