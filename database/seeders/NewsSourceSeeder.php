<?php

namespace Database\Seeders;

use App\Models\NewsSource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NewsSourceSeeder extends Seeder
{
    public function run(): void
    {
        $trusted = [
            // Sports
            ['domain' => 'espn.com',                    'name' => 'ESPN',                   'category' => 'Sports'],
            ['domain' => 'nfl.com',                     'name' => 'NFL',                    'category' => 'Sports'],
            ['domain' => 'nba.com',                     'name' => 'NBA',                    'category' => 'Sports'],
            ['domain' => 'mlb.com',                     'name' => 'MLB',                    'category' => 'Sports'],
            ['domain' => 'nhl.com',                     'name' => 'NHL',                    'category' => 'Sports'],
            ['domain' => 'cbssports.com',               'name' => 'CBS Sports',             'category' => 'Sports'],
            ['domain' => 'nbcsports.com',               'name' => 'NBC Sports',             'category' => 'Sports'],
            ['domain' => 'foxsports.com',               'name' => 'Fox Sports',             'category' => 'Sports'],
            ['domain' => 'theathletic.com',             'name' => 'The Athletic',           'category' => 'Sports'],
            ['domain' => 'sportingnews.com',            'name' => 'Sporting News',          'category' => 'Sports'],
            ['domain' => 'bleacherreport.com',          'name' => 'Bleacher Report',        'category' => 'Sports'],
            ['domain' => 'si.com',                      'name' => 'Sports Illustrated',     'category' => 'Sports'],
            ['domain' => 'profootballtalk.nbcsports.com','name' => 'PFT',                   'category' => 'Sports'],
            ['domain' => 'nfltraderumors.co',           'name' => 'NFL Trade Rumors',       'category' => 'Sports'],
            ['domain' => 'pro-football-reference.com',  'name' => 'PFR',                    'category' => 'Sports'],
            ['domain' => 'basketball-reference.com',    'name' => 'BBRef',                  'category' => 'Sports'],
            ['domain' => 'pff.com',                     'name' => 'PFF',                    'category' => 'Sports'],
            ['domain' => 'draftwire.usatoday.com',      'name' => 'Draft Wire',             'category' => 'Sports'],
            ['domain' => 'nflnetwork.com',              'name' => 'NFL Network',            'category' => 'Sports'],
            ['domain' => 'nflpa.com',                   'name' => 'NFLPA',                  'category' => 'Sports'],
            ['domain' => 'theringer.com',               'name' => 'The Ringer',             'category' => 'Sports'],
            ['domain' => 'fansided.com',                'name' => 'FanSided',               'category' => 'Sports'],
            ['domain' => 'yardbarker.com',              'name' => 'Yardbarker',             'category' => 'Sports'],
            ['domain' => 'clutchpoints.com',            'name' => 'ClutchPoints',           'category' => 'Sports'],
            ['domain' => 'heavy.com',                   'name' => 'Heavy',                  'category' => 'Sports'],
            ['domain' => 'twsn.com',                    'name' => 'TWSN',                   'category' => 'Sports'],
            ['domain' => 'landryhat.com',               'name' => 'The Landry Hat',         'category' => 'Sports'],
            ['domain' => 'mmafighting.com',             'name' => 'MMA Fighting',           'category' => 'Sports'],
            ['domain' => 'ufc.com',                     'name' => 'UFC',                    'category' => 'Sports'],
            ['domain' => 'skysports.com',               'name' => 'Sky Sports',             'category' => 'Sports'],
            ['domain' => 'goal.com',                    'name' => 'Goal',                   'category' => 'Sports'],
            // SB Nation team blogs — cover ALL team subdomains (arrowheadpride, bloggingtheboys, etc.)
            ['domain' => 'sbnation.com',                'name' => 'SB Nation',              'category' => 'Sports'],
            ['domain' => 'arrowheadpride.com',          'name' => 'Arrowhead Pride (Chiefs)','category' => 'Sports'],
            ['domain' => 'bloggingtheboys.com',         'name' => 'Blogging The Boys (Cowboys)','category' => 'Sports'],
            ['domain' => 'patspulpit.com',              'name' => 'Pats Pulpit (Patriots)', 'category' => 'Sports'],
            ['domain' => 'bigblueview.com',             'name' => 'Big Blue View (Giants)', 'category' => 'Sports'],
            ['domain' => 'thefalcoholic.com',           'name' => 'The Falcoholic (Falcons)','category' => 'Sports'],
            ['domain' => 'turfshowtimes.com',           'name' => 'Turf Show Times (Rams)', 'category' => 'Sports'],
            ['domain' => 'fieldgulls.com',              'name' => 'Field Gulls (Seahawks)', 'category' => 'Sports'],
            ['domain' => 'milehighreport.com',          'name' => 'Mile High Report (Broncos)','category' => 'Sports'],
            // FanSided team sites
            ['domain' => 'kckingdom.com',               'name' => 'KC Kingdom (Chiefs)',    'category' => 'Sports'],
            ['domain' => 'nflspinzone.com',             'name' => 'NFL Spin Zone',          'category' => 'Sports'],
            ['domain' => 'thechupacabra.com',           'name' => 'The Chupacabra',         'category' => 'Sports'],
            // Local KC / team market papers
            ['domain' => 'kansascity.com',              'name' => 'Kansas City Star',       'category' => 'Sports'],
            ['domain' => 'star-telegram.com',           'name' => 'Fort Worth Star-Telegram','category' => 'Sports'],
            ['domain' => 'bostonherald.com',            'name' => 'Boston Herald',          'category' => 'Sports'],
            // Tier-1 News
            ['domain' => 'apnews.com',                  'name' => 'AP News',                'category' => 'News'],
            ['domain' => 'reuters.com',                 'name' => 'Reuters',                'category' => 'News'],
            ['domain' => 'bbc.com',                     'name' => 'BBC',                    'category' => 'News'],
            ['domain' => 'cnn.com',                     'name' => 'CNN',                    'category' => 'News'],
            ['domain' => 'nbcnews.com',                 'name' => 'NBC News',               'category' => 'News'],
            ['domain' => 'cbsnews.com',                 'name' => 'CBS News',               'category' => 'News'],
            ['domain' => 'usatoday.com',                'name' => 'USA Today',              'category' => 'News'],
            ['domain' => 'nytimes.com',                 'name' => 'NY Times',               'category' => 'News'],
            ['domain' => 'washingtonpost.com',          'name' => 'Washington Post',        'category' => 'News'],
            ['domain' => 'theguardian.com',             'name' => 'The Guardian',           'category' => 'News'],
            ['domain' => 'politico.com',                'name' => 'Politico',               'category' => 'News'],
            ['domain' => 'axios.com',                   'name' => 'Axios',                  'category' => 'News'],
            ['domain' => 'nypost.com',                  'name' => 'NY Post',                'category' => 'News'],
            ['domain' => 'newsweek.com',                'name' => 'Newsweek',               'category' => 'News'],
            ['domain' => 'time.com',                    'name' => 'Time',                   'category' => 'News'],
            // Tech
            ['domain' => 'techcrunch.com',              'name' => 'TechCrunch',             'category' => 'Tech'],
            ['domain' => 'theverge.com',                'name' => 'The Verge',              'category' => 'Tech'],
            ['domain' => 'wired.com',                   'name' => 'Wired',                  'category' => 'Tech'],
            ['domain' => 'arstechnica.com',             'name' => 'Ars Technica',           'category' => 'Tech'],
            ['domain' => 'cnet.com',                    'name' => 'CNET',                   'category' => 'Tech'],
            // Entertainment
            ['domain' => 'variety.com',                 'name' => 'Variety',                'category' => 'Entertainment'],
            ['domain' => 'hollywoodreporter.com',       'name' => 'Hollywood Reporter',     'category' => 'Entertainment'],
            ['domain' => 'deadline.com',                'name' => 'Deadline',               'category' => 'Entertainment'],
            ['domain' => 'tmz.com',                     'name' => 'TMZ',                    'category' => 'Entertainment'],
            ['domain' => 'people.com',                  'name' => 'People',                 'category' => 'Entertainment'],
            // Finance
            ['domain' => 'bloomberg.com',               'name' => 'Bloomberg',              'category' => 'Finance'],
            ['domain' => 'wsj.com',                     'name' => 'WSJ',                    'category' => 'Finance'],
            ['domain' => 'cnbc.com',                    'name' => 'CNBC',                   'category' => 'Finance'],
            ['domain' => 'forbes.com',                  'name' => 'Forbes',                 'category' => 'Finance'],
            // Health
            ['domain' => 'webmd.com',                   'name' => 'WebMD',                  'category' => 'Health'],
            ['domain' => 'healthline.com',              'name' => 'Healthline',             'category' => 'Health'],
            // Local US
            ['domain' => 'dallasnews.com',              'name' => 'Dallas News',            'category' => 'Local'],
            ['domain' => 'latimes.com',                 'name' => 'LA Times',               'category' => 'Local'],
            ['domain' => 'bostonglobe.com',             'name' => 'Boston Globe',           'category' => 'Local'],
            // Plane / Aviation / Airline
            ['domain' => 'simpleflying.com',            'name' => 'Simple Flying',          'category' => 'Plane'],
            ['domain' => 'airlinegeeks.com',            'name' => 'Airline Geeks',          'category' => 'Plane'],
            ['domain' => 'aviationweek.com',            'name' => 'Aviation Week',          'category' => 'Plane'],
            ['domain' => 'flightglobal.com',            'name' => 'Flight Global',          'category' => 'Plane'],
            ['domain' => 'theaircurrent.com',           'name' => 'The Air Current',        'category' => 'Plane'],
            ['domain' => 'ainonline.com',               'name' => 'AIN Online',             'category' => 'Plane'],
            ['domain' => 'atwonline.com',               'name' => 'Air Transport World',    'category' => 'Plane'],
            ['domain' => 'ch-aviation.com',             'name' => 'CH-Aviation',            'category' => 'Plane'],
            ['domain' => 'centreforaviation.com',       'name' => 'CAPA',                   'category' => 'Plane'],
            ['domain' => 'skift.com',                   'name' => 'Skift',                  'category' => 'Plane'],
            ['domain' => 'onemileatatime.com',          'name' => 'One Mile at a Time',     'category' => 'Plane'],
            ['domain' => 'thepointsguy.com',            'name' => 'The Points Guy',         'category' => 'Plane'],
            ['domain' => 'viewfromthewing.com',         'name' => 'View from the Wing',     'category' => 'Plane'],
            ['domain' => 'crankyflier.com',             'name' => 'Cranky Flier',           'category' => 'Plane'],
            ['domain' => 'avweb.com',                   'name' => 'AVweb',                  'category' => 'Plane'],
            ['domain' => 'flyingmag.com',               'name' => 'Flying Magazine',        'category' => 'Plane'],
            ['domain' => 'aviationpros.com',            'name' => 'Aviation Pros',          'category' => 'Plane'],
            ['domain' => 'travelpulse.com',             'name' => 'Travel Pulse',           'category' => 'Plane'],
        ];

        $blocked = [
            ['domain' => 'reddit.com',          'name' => 'Reddit',         'category' => 'UGC'],
            ['domain' => 'quora.com',           'name' => 'Quora',          'category' => 'UGC'],
            ['domain' => 'medium.com',          'name' => 'Medium',         'category' => 'UGC'],
            ['domain' => 'blogspot.com',        'name' => 'Blogspot',       'category' => 'UGC'],
            ['domain' => 'wordpress.com',       'name' => 'WordPress',      'category' => 'UGC'],
            ['domain' => 'substack.com',        'name' => 'Substack',       'category' => 'UGC'],
            ['domain' => 'yahoo.com',           'name' => 'Yahoo',          'category' => 'Aggregator'],
            ['domain' => 'msn.com',             'name' => 'MSN',            'category' => 'Aggregator'],
            ['domain' => 'smartnews.com',       'name' => 'SmartNews',      'category' => 'Aggregator'],
            ['domain' => 'buzzfeed.com',        'name' => 'BuzzFeed',       'category' => 'Clickbait'],
            ['domain' => 'dailymail.co.uk',     'name' => 'Daily Mail',     'category' => 'Clickbait'],
            ['domain' => 'theonion.com',        'name' => 'The Onion',      'category' => 'Satire'],
            ['domain' => 'babylonbee.com',      'name' => 'Babylon Bee',    'category' => 'Satire'],
            // Low-quality / off-topic sports coverage
            ['domain' => 'timesofindia.com',    'name' => 'Times of India', 'category' => 'Low Quality'],
            ['domain' => 'nationaltoday.com',   'name' => 'National Today', 'category' => 'Low Quality'],
            ['domain' => 'atozfootball.com',    'name' => 'A to Z Football','category' => 'Low Quality'],
            ['domain' => 'atozsports.com',      'name' => 'A to Z Sports',  'category' => 'Low Quality'],
            ['domain' => 'sportskeeda.com',     'name' => 'Sportskeeda',    'category' => 'Low Quality'],
            ['domain' => 'givemesport.com',     'name' => 'GiveMeSport',    'category' => 'Low Quality'],
            ['domain' => 'essentially-sports.com','name' => 'Essentially Sports','category' => 'Low Quality'],
        ];

        foreach ($trusted as $data) {
            NewsSource::updateOrCreate(
                ['domain' => $data['domain']],
                array_merge($data, ['id' => Str::uuid(), 'type' => 'trusted', 'is_active' => true])
            );
        }

        foreach ($blocked as $data) {
            NewsSource::updateOrCreate(
                ['domain' => $data['domain']],
                array_merge($data, ['id' => Str::uuid(), 'type' => 'blocked', 'is_active' => true])
            );
        }
    }
}
