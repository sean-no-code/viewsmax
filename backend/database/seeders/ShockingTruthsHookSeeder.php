<?php

namespace Database\Seeders;

use App\Models\LibraryComponent;
use App\Models\LibraryComponentTag;
use App\Models\User;
use Illuminate\Database\Seeder;

class ShockingTruthsHookSeeder extends Seeder
{
    public const TAG_NAME = 'shocking truths';

    /**
     * Seed the "Shocking Truths & Discoveries" hook pack into every user's
     * component library. Safe to re-run: existing components are skipped.
     */
    public function run(): void
    {
        $users = User::all();
        if ($users->isEmpty()) {
            $this->command?->warn('No users found. Please run UserSeeder first.');

            return;
        }

        $tag = LibraryComponentTag::firstOrCreate(['name' => self::TAG_NAME]);
        $hooks = $this->hooks();

        foreach ($users as $user) {
            foreach ($hooks as [$title, $example]) {
                $component = LibraryComponent::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'type' => LibraryComponent::TYPE_HOOK,
                        'title' => $title,
                    ],
                    [
                        'body' => $example,
                    ]
                );

                $component->tags()->syncWithoutDetaching([$tag->id]);
            }
        }

        $this->command?->info(sprintf(
            'Seeded %d "%s" hooks for %d user(s).',
            count($hooks),
            self::TAG_NAME,
            $users->count()
        ));
    }

    /**
     * Parse RAW_HOOKS into [template, example] pairs, deduplicated by
     * template (first example wins).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function hooks(): array
    {
        $hooks = [];

        foreach (explode("\n", self::RAW_HOOKS) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '|||')) {
                continue;
            }

            [$title, $example] = array_map('trim', explode('|||', $line, 2));
            $key = mb_strtolower(preg_replace('/\s+/', ' ', $title));

            if ($title === '' || $example === '' || isset($hooks[$key])) {
                continue;
            }

            $hooks[$key] = [$title, $example];
        }

        return array_values($hooks);
    }

    /**
     * One hook per line: `<template> ||| <example>`.
     * Duplicate templates across batches are skipped at parse time.
     */
    private const RAW_HOOKS = <<<'HOOKS'
I tried every [thing] so you don't have to. ||| I tried every productivity app so you don't have to.
Everything you know about [topic] is wrong. ||| Everything you know about dieting is wrong.
The ugly truth about [topic]. ||| The ugly truth about "hustle culture."
No one talks about this side of [topic]. ||| No one talks about this side of freelancing.
The [industry] lie you've been believing. ||| The skincare lie you've been believing.
Here's what [experts/industry] don't want you to know. ||| Here's what real estate agents don't want you to know.
This myth is killing your [result]. ||| This myth is killing your Instagram reach.
The shocking truth about [common belief]. ||| The shocking truth about "8 glasses of water a day."
You've been lied to about [topic]. ||| You've been lied to about investing.
The secret side of [topic] nobody shows. ||| The secret side of influencer marketing nobody shows.
The biggest scam in [industry]. ||| The biggest scam in online coaching.
The crazy truth about [thing you use daily]. ||| The crazy truth about bottled water.
What I found out about [topic] shocked me. ||| What I found out about bank fees shocked me.
Nobody's ready for this truth about [topic]. ||| Nobody's ready for this truth about content creation.
This "healthy" thing is actually ruining your [result]. ||| This "healthy" snack is actually ruining your fat loss.
What if I told you [contradiction to common belief]? ||| What if I told you success has nothing to do with talent?
The dark truth about [industry/trend]. ||| The dark truth about fast fashion.
This discovery changes everything about [topic]. ||| This discovery changes everything about sleep.
Why [thing you rely on] is actually broken. ||| Why your morning routine is actually broken.
This one mistake is costing you [money/result]. ||| This one mistake is costing you thousands in ads.
The disturbing truth about [topic]. ||| The disturbing truth about your skincare products.
This blew my mind about [topic]. ||| This blew my mind about coffee.
Here's what no one prepares you for in [topic]. ||| Here's what no one prepares you for in entrepreneurship.
The [number] biggest lies about [topic]. ||| The 5 biggest lies about social media growth.
The crazy part about [topic] nobody mentions. ||| The crazy part about side hustles nobody mentions.
If you thought [thing] was safe, think again. ||| If you thought your phone data was safe, think again.
This is why [popular advice] is failing you. ||| This is why "just post daily" is failing your Instagram growth.
The most shocking fact I've learned about [topic]. ||| The most shocking fact I've learned about human attention span.
This is the dark side of [good thing]. ||| This is the dark side of remote work.
The truth about [trending topic] is not what you think. ||| The truth about AI tools is not what you think.
What I discovered about [topic] changed everything. ||| What I discovered about Instagram's algorithm changed everything.
Nobody wants you to know this about [topic]. ||| Nobody wants you to know this about credit cards.
The truth about [everyday thing] will shock you. ||| The truth about your toothpaste will shock you.
This [industry] secret is finally out. ||| This real estate secret is finally out.
You're being lied to about [topic]. ||| You're being lied to about the "4-hour work week."
If you thought [thing] was safe, it's not. ||| If you thought free Wi-Fi was safe, it's not.
The hidden danger in [topic]. ||| The hidden danger in energy drinks.
You won't believe what I found about [topic]. ||| You won't believe what I found about airline tickets.
The crazy truth about [trend]. ||| The crazy truth about crypto trading.
This truth will change how you see [topic]. ||| This truth will change how you see branding forever.
Why [thing you trust] is actually broken. ||| Why your school education is actually broken.
The most shocking mistake I've ever made. ||| The most shocking mistake I've ever made in business.
This truth about [topic] will piss people off. ||| This truth about the 9-5 grind will piss people off.
This one detail changes everything about [topic]. ||| This one detail changes everything about social media growth.
Here's why [common belief] is a lie. ||| Here's why "more followers = more sales" is a lie.
You've been [doing something] wrong this whole time. ||| You've been brushing your teeth wrong this whole time.
This shocked me about [topic]. ||| This shocked me about Instagram ads.
The truth nobody admits about [topic]. ||| The truth nobody admits about working from home.
This truth about [topic] is worse than you think. ||| This truth about sugar addiction is worse than you think.
If you knew this, you'd never [common action]. ||| If you knew this, you'd never buy followers again.
The untold story about [topic]. ||| The untold story about how influencers actually make money.
The hidden truth behind [everyday action]. ||| The hidden truth behind drinking bottled water.
This will completely change how you see [topic]. ||| This will completely change how you see money.
The dark reality of [industry/trend]. ||| The dark reality of coaching businesses.
What you don't know about [topic] will shock you. ||| What you don't know about skincare will shock you.
This secret explains everything about [topic]. ||| This secret explains everything about algorithm updates.
If you believe [myth], prepare to be shocked. ||| If you believe "sleep is for the weak," prepare to be shocked.
Here's the shocking part about [topic]. ||| Here's the shocking part about hustle culture.
The truth behind [common saying]. ||| The truth behind "money can't buy happiness."
This shocking fact will change your day. ||| This shocking fact about productivity will change your day.
The dirty truth about [industry]. ||| The dirty truth about online business coaches.
I just found out [topic] — and it's insane. ||| I just found out how Instagram ranks posts — and it's insane.
Why nobody wants to admit this truth. ||| Why nobody wants to admit the truth about being self-employed.
This fact about [topic] changes everything. ||| This fact about sleep changes everything.
The uncomfortable truth about [topic]. ||| The uncomfortable truth about chasing money.
The biggest scam nobody talks about. ||| The biggest scam nobody talks about in online courses.
If you think [thing] is safe, it's not. ||| If you think working a 9-5 is safe, it's not.
This truth will upset a lot of people. ||| This truth about diets will upset a lot of people.
What you thought you knew about [topic] is a lie. ||| What you thought you knew about success is a lie.
The shocking truth about [industry tool]. ||| The shocking truth about ChatGPT.
The disturbing reality about [trend]. ||| The disturbing reality about fast fashion.
This truth about [topic] changed my life. ||| This truth about investing changed my life.
The one thing nobody told you about [topic]. ||| The one thing nobody told you about building an audience.
If you care about [issue], you need to hear this truth. ||| If you care about health, you need to hear this truth about sugar.
The lie most people believe about [topic]. ||| The lie most people believe about success.
This truth makes everything else useless. ||| This truth about content makes every other "hack" useless.
Here's the scary truth about [topic]. ||| Here's the scary truth about credit card debt.
Nobody saw this truth coming. ||| Nobody saw this truth about AI coming.
What I learned about [topic] is terrifying. ||| What I learned about data privacy is terrifying.
The uncomfortable truth no one wants to say. ||| The uncomfortable truth no one wants to say about working hard.
This is the real story behind [topic]. ||| This is the real story behind the 4-day workweek.
This truth about [topic] is worse than the rumors. ||| This truth about diets is worse than the rumors.
This truth about [topic] saved me. ||| This truth about content creation saved me from burnout.
The shocking truth nobody sees. ||| The shocking truth nobody sees about fame.
This truth about [topic] will surprise you. ||| This truth about money will surprise you.
This fact will make you rethink [topic]. ||| This fact will make you rethink side hustles.
If you knew this, you'd never [habit]. ||| If you knew this, you'd never skip stretching again.
The shocking truth about [thing you love]. ||| The shocking truth about coffee.
This is what nobody wants to admit. ||| This is what nobody wants to admit about online business.
What if everything you knew about [topic] was wrong? ||| What if everything you knew about making money was wrong?
The truth behind [industry trend]. ||| The truth behind TikTok virality.
This shocking truth will wake you up. ||| This shocking truth about side hustles will wake you up.
This truth about [topic] made me angry. ||| This truth about college degrees made me angry.
Here's what nobody told you about [topic]. ||| Here's what nobody told you about growing on Instagram.
This truth about [topic] shocked me the most. ||| This truth about branding shocked me the most.
The hidden cost of [topic]. ||| The hidden cost of freelancing.
This truth about [topic] will change your future. ||| This truth about social media will change your future.
The truth about [topic] is crazier than the rumors. ||| The truth about hustle culture is crazier than the rumors.
I tried every ___ so you don't have to. ||| I tried every Instagram growth hack so you don't have to.
Everything you know about ___ is WRONG! ||| Everything you know about dieting is WRONG!
This is the only thing you need to know about ___. ||| This is the only thing you need to know about content that goes viral.
What most [experts/industry] don't want you to know. ||| What most personal trainers don't want you to know about fat loss.
This harmful myth is ruining [industry/topic]. ||| This harmful myth is ruining skincare routines.
The ugly truth about [industry/topic]. ||| The ugly truth about the coaching industry.
It could not be any more obvious that... ||| It could not be any more obvious that most people are addicted to their phones.
Most people suck at [pain point]. ||| Most people suck at managing their money.
Nobody's talking about this, but... ||| Nobody's talking about this, but your morning coffee ruins your sleep.
No one told you this about [topic]. ||| No one told you this about Instagram's algorithm.
No one told you this until now, but [belief] is false. ||| No one told you this until now, but "8 glasses of water a day" is a myth.
Just when you thought [topic] couldn't get crazier... ||| Just when you thought AI couldn't get crazier, it just learned sarcasm.
I wasn't ready for what happened next. ||| I wasn't ready for what happened when I quit sugar for 30 days.
Here's the most underestimated secret about [topic]. ||| Here's the most underestimated secret about productivity.
I don't know who needs to hear this but... ||| I don't know who needs to hear this but "motivation" is overrated — you need systems.
What's keeping you from [goal]. ||| What's keeping you from building your dream business.
Breaking news: [trend/secret revealed]. ||| Breaking news: Instagram is secretly testing a new feature.
Exposing my secret to [result]. ||| Exposing my secret to selling out my course in 24 hours.
Did you know [shocking stat/fact]? ||| Did you know 80% of businesses fail within 5 years?
Here's why [thing] is worth every penny. ||| Here's why investing in good sleep is worth every penny.
Here's the before and after of [topic]. ||| Here's the before and after of fixing my productivity habits.
Here's the secret to [goal]. ||| Here's the secret to creating binge-worthy content.
I was wrong about [belief]. ||| I was wrong about hustle culture — it almost burned me out.
Why [method] is a game-changer. ||| Why morning walks are a game-changer for creativity.
You won't believe what I found out about [topic]. ||| You won't believe what I found out about credit card companies.
You'll never guess what I found out about [topic]. ||| You'll never guess what I found out about Instagram hashtags.
This one thing changed everything for me. ||| This one email script changed everything for my sales.
I'm not a fan of [popular method] — here's why. ||| I'm not a fan of cold calling — here's why it fails in 2025.
If you're a [target audience], you're not going to like this... ||| If you're a content creator, you're not going to like this truth about virality.
If you're tired of [pain point], you need this. ||| If you're tired of wasting hours editing, you need this shortcut.
I was amazed by the results of [experiment]. ||| I was amazed by the results of waking up at 5 AM for 30 days.
This might be controversial, but [truth]. ||| This might be controversial, but not everyone should start a business.
This mistake is going to cost you [big consequence]. ||| This mistake in your bio could cost you thousands of followers.
This should be illegal. ||| This should be illegal — selling supplements with zero proof.
Turns out everything we knew about [topic] was wrong. ||| Turns out everything we knew about multitasking was wrong.
Wait until you see the end of this. ||| Wait until you see the end of this side hustle story.
The dark secret behind [topic]. ||| The dark secret behind the coaching industry.
The shocking truth about [industry]. ||| The shocking truth about fast fashion.
The biggest secret to [result] revealed. ||| The biggest secret to closing high-ticket sales revealed.
This might get me canceled, but I'll say it anyway. ||| This might get me canceled, but hustle culture is toxic.
I was shocked at how effective this is. ||| I was shocked at how effective a simple sleep routine was for my energy.
Why [something "good"] is actually bad for you. ||| Why your morning coffee might actually be killing your sleep.
After watching this, you'll never [action] the same way again. ||| After watching this, you'll never scroll social media the same way again.
I cracked the code of [topic]. ||| I cracked the code of Instagram Reels.
Here's why you can't succeed at [goal] without this. ||| Here's why you can't succeed at YouTube growth without understanding retention.
Here's something that'll ruin your day. ||| Here's something that'll ruin your day: that "low-fat" snack has more sugar than soda.
Here's the secret [experts] don't want you to know. ||| Here's the secret real estate agents don't want you to know about buying homes.
Warning to [audience] — this changes everything. ||| Warning to freelancers: this AI tool just replaced half your workload.
Why is nobody talking about [topic]? ||| Why is nobody talking about the psychology of pricing?
I can't get over how [unexpected benefit] changed my [outcome]. ||| I can't get over how one journaling habit changed my sales clarity.
I bought this for [purpose] — here's what happened. ||| I bought this planner for focus, and it completely rewired my day.
You've been doing [process] wrong this whole time. ||| You've been drinking water wrong this whole time.
Here's why [common practice] is holding you back. ||| Here's why multitasking is holding you back from real progress.
Here's why you're not getting results with [action]. ||| Here's why you're not getting results with Instagram growth.
I can't believe no one told me this... ||| I can't believe no one told me how much sleep impacts creativity.
I can't believe this is real. ||| I can't believe this is real — an AI that writes your ad copy in seconds.
This is why [popular advice] is misleading. ||| This is why "posting more" is misleading for Instagram growth.
This is why [popular trend] is overrated. ||| This is why "rise and grind" is overrated.
This mistake will cost you big time. ||| This mistake in your pricing strategy will cost you thousands.
This one mistake could be costing you [amount]. ||| This one mistake in your ads could be costing you $500 a week.
This should honestly be illegal. ||| This should honestly be illegal — selling sugar as "healthy cereal."
Turns out everything we thought about [topic] was wrong. ||| Turns out everything we thought about creativity was wrong.
What if I told you there's a simple way to [goal]. ||| What if I told you there's a simple way to save 5 hours a week?
This one thing could change everything. ||| This one mindset shift could change everything in your career.
I can't believe how easy this was. ||| I can't believe how easy it was to double my engagement with this hack.
The dark secret behind [industry]. ||| The dark secret behind fast fashion.
The shocking truth about [topic]. ||| The shocking truth about content creators.
This controversial belief changed my life. ||| This controversial belief — stop chasing balance — changed my life.
Controversial opinion incoming... ||| Controversial opinion incoming: most "hustlers" are just busy, not productive.
This is the biggest secret to [goal] (and it's not what you think). ||| This is the biggest secret to creating content that sells (and it's not more posting).
The biggest lies in [industry] exposed. ||| The biggest lies in the coaching industry exposed.
Stop [action], start [action]. ||| Stop chasing followers, start chasing impact.
This is so good it's banned. ||| This productivity hack is so good it's banned in some workplaces.
What [industry] doesn't want you to know about [topic]. ||| What the food industry doesn't want you to know about sugar.
This will change the way you use [tool/platform]. ||| This will change the way you use Instagram forever.
This one mistake could be costing you [result]. ||| This one mistake could be costing you your audience's trust.
This is my most controversial belief. ||| This is my most controversial belief: hard work doesn't beat leverage.
You won't believe what this is. ||| You won't believe what this AI tool can do for you.
This might get me canceled, but I'll say it. ||| This might get me canceled, but college is not worth the debt.
I can't believe this worked. ||| I can't believe this one tweak doubled my sales calls.
The truth about [topic] will leave you speechless. ||| The truth about bottled water will leave you speechless.
You won't believe what's inside [thing]. ||| You won't believe what's inside your daily skincare cream.
This fact about [topic] will blow your mind. ||| This fact about dopamine will blow your mind.
The truth about [habit] is scarier than you think. ||| The truth about sitting 8 hours a day is scarier than you think.
I couldn't believe this about [topic] — but it's true. ||| I couldn't believe this about how credit scores work — but it's true.
The shocking reality of [trend]. ||| The shocking reality of hustle culture.
Here's what they don't want you to know. ||| Here's what they don't want you to know about loans.
They've been hiding this from you. ||| They've been hiding this from you about processed foods.
I thought I knew everything about [topic] — I was wrong. ||| I thought I knew everything about content — I was wrong.
[Topic] just got exposed — and it's worse than we thought. ||| Instagram's algorithm just got exposed — and it's worse than we thought.
You'll never look at [thing] the same way again. ||| You'll never look at your phone the same way again.
Experts just revealed the truth about [topic]. ||| Experts just revealed the truth about coffee addiction.
This secret about [topic] will change the way you think. ||| This secret about pricing psychology will change the way you think forever.
The truth about [common belief] might shock you. ||| The truth about multitasking might shock you: it actually lowers productivity.
What if I told you [trusted advice] was wrong? ||| What if I told you drinking 8 glasses of water a day isn't backed by science?
The dark side of [industry] nobody wants to admit. ||| The dark side of influencer marketing nobody wants to admit: fake followers.
You've been misled about [topic] your whole life. ||| You've been misled about breakfast being the most important meal of the day.
This is the harsh reality of [topic]. ||| Harsh reality of freelancing: some months you'll earn $5K, some months $0.
Here's the biggest lie you've been told about [topic]. ||| Biggest lie about success: working harder = better results.
Nobody talks about this dark truth. ||| Nobody talks about this dark truth: success can feel lonely.
This is the most shocking fact I've ever learned. ||| Most shocking fact I've learned: plastic never really disappears.
Here's the uncomfortable truth about [habit]. ||| Uncomfortable truth about scrolling at night: it ruins your sleep cycle.
This is what nobody prepares you for. ||| Nobody prepares you for how lonely building a business can feel.
Here's what they're not telling you about [topic]. ||| What they're not telling you about credit cards: rewards are designed to keep you in debt.
What [industry] doesn't want you to know. ||| What the fitness industry doesn't want you to know: you don't need supplements.
This is the real reason [unexpected outcome] happens. ||| The real reason your posts don't go viral: people scroll past in the first 2 seconds.
Why everything you know about [topic] is wrong. ||| Everything you know about dieting is wrong: it's not about eating less.
The shocking part about [trend] nobody discusses. ||| Shocking part about remote work: people are lonelier than ever.
This is why your [current habit] is failing you. ||| Why your morning routine is failing you: it's not built for your energy cycle.
The truth about [daily behavior] is worse than you think. ||| The truth about sitting all day is worse than you think: it shortens your lifespan.
This discovery changes everything. ||| This discovery about sleep cycles changes everything about productivity.
The secret about [habit] that nobody admits. ||| Secret about morning journaling: most people quit after 2 weeks.
This is the most overlooked truth in [industry]. ||| Most overlooked truth in marketing: boring consistency beats creative bursts.
Nobody's ready to hear this... ||| Nobody's ready to hear this, but success won't make you happy.
This might get me canceled, but here's the truth. ||| Might get me canceled, but hustle culture is destroying mental health.
The truth is uglier than the conspiracy. ||| Truth about fast fashion is uglier than the conspiracy: it's exploitative.
This one fact blew my mind. ||| Fact: Your phone listens to you even when apps are closed.
This is why [popular belief] is a trap. ||| Why 'following your passion' can be a trap for entrepreneurs.
What I discovered about [topic] still haunts me. ||| What I discovered about food labels still haunts me: 'natural' doesn't mean healthy.
This is what nobody wants to admit about [topic]. ||| Nobody wants to admit: most people don't want financial freedom, they want comfort.
Here's why your [goal] isn't working. ||| Here's why your workouts aren't working: your diet matters more.
The most shocking thing I learned about [topic]. ||| Most shocking thing I learned about sleep: alarms cause stress spikes.
The truth about [trend] will surprise you. ||| Truth about intermittent fasting will surprise you: it's not for everyone.
This might hurt to hear, but it's true. ||| Might hurt, but your 9-5 is safer than 90% of startups.
You've been told this your whole life — but it's wrong. ||| Told your whole life breakfast is essential? Science says maybe not.
The one thing nobody wants you to realize. ||| Nobody wants you to realize: most gurus make money teaching, not doing.
This is the shocking truth about [popular habit]. ||| Shocking truth about social media: likes don't equal sales.
This will make you rethink [common practice]. ||| Will make you rethink buying bottled water.
This is why your [assumption] is false. ||| Why your assumption about rich people being happy is false.
The one truth I learned the hard way. ||| Truth I learned the hard way: friends don't always support your success.
Nobody is talking about this reality. ||| Nobody talks about how much entrepreneurship affects relationships.
This fact will completely change how you see [topic]. ||| Fact: humans spend 6+ years of life on social media. Let that sink in.
The truth about [topic] nobody wants to face. ||| Truth about entrepreneurship: most people fail.
Here's the harsh truth nobody's willing to say. ||| Harsh truth: posting more content won't fix bad content.
The most shocking thing about [industry] is how normal it seems. ||| Shocking thing about fast fashion: it looks cheap but costs the planet billions.
This is why most people never make it. ||| Why most people never make it in business: they quit right before momentum kicks in.
What nobody told you about [everyday habit]. ||| Nobody told you that working late at night damages memory.
This truth about [popular tool/trend] will sting. ||| Truth about AI tools: they won't fix bad ideas.
The dark reality behind [dream]. ||| Dark reality behind going viral: it doesn't guarantee sales.
This fact is going to change the way you think. ||| Fact: the average person spends 3 years of life just checking email.
The shocking reason [expected result] doesn't happen. ||| Shocking reason your content isn't blowing up: it's too confusing.
Nobody talks about how bad this really is. ||| Nobody talks about how bad burnout really is until you can't get out of bed.
Here's why [common path] doesn't work anymore. ||| Why the college-to-career path doesn't work anymore.
This truth about [success/goal] will shock you. ||| Truth about overnight success: it usually takes 10 years.
You've been doing [everyday thing] wrong this whole time. ||| You've been brushing your teeth wrong this whole time — most people do.
The uncomfortable reality about [topic]. ||| Uncomfortable reality about entrepreneurship: 80% of people lose money.
What if I told you [trusted habit] is actually harmful? ||| What if I told you working harder every day is actually slowing you down?
The ugly side of [trend] nobody shares. ||| Ugly side of the self-care trend: people use it as an excuse to avoid responsibility.
This is the reality nobody posts online. ||| Reality nobody posts: running a business can feel like gambling with your savings.
Here's the painful truth about [dream]. ||| Painful truth about being your own boss: you'll work harder than ever.
The shocking truth about [industry] that'll blow your mind. ||| Shocking truth about advertising: the biggest companies barely use ads.
Nobody told me this until it was too late. ||| Nobody told me that growth means losing some friends.
The truth behind [trend] is darker than you think. ||| Truth behind influencer culture is darker than you think: many fake their lifestyle.
Here's what [experts/mentors] never tell you. ||| What productivity gurus never tell you: they don't follow all their own hacks.
This discovery about [topic] ruined me. ||| Discovery about food labels ruined me: 'low-fat' usually means more sugar.
The truth about [routine] will surprise you. ||| Truth about morning routines: most successful people don't have strict ones.
This is why most advice about [topic] is useless. ||| Why most advice about getting rich is useless: it's outdated.
The most shocking part of [journey]. ||| Most shocking part of building a business: strangers support you more than friends.
Nobody talks about the hidden cost of [achievement]. ||| Nobody talks about the hidden cost of success: sacrificing peace of mind.
What if I told you [comforting belief] was a lie? ||| What if I told you 'time heals everything' is a lie?
This is the untold truth about [trend/tool]. ||| Untold truth about ChatGPT: it's only as smart as the person using it.
Here's what I wish I knew before chasing [goal]. ||| Wish I knew before chasing entrepreneurship: freedom comes with anxiety.
The shocking thing nobody says about [struggle]. ||| Nobody says this about burnout: sometimes the only fix is quitting.
The truth about [habit] will ruin the way you see it. ||| Truth about energy drinks: they give you energy debt, not energy.
Here's what I found out the hard way. ||| Found out the hard way: going viral doesn't guarantee clients.
This is the one thing nobody wants you to discover. ||| Nobody wants you to discover how cheap marketing ads really are to run.
Here's the brutal truth about [dream outcome]. ||| Brutal truth about passive income: it usually requires years of upfront work.
I can't believe this truth about [topic]. ||| Can't believe this truth about skincare: natural doesn't mean better.
The truth is, [contradictory statement]. ||| Truth is, being busy isn't the same as being productive.
The thing that shocked me most about [success/failure]. ||| Shocked me most about success: it feels emptier than I expected.
Nobody prepares you for this truth. ||| Nobody prepares you for how fast success can feel overwhelming.
This is the secret dark side of [achievement]. ||| Secret dark side of wealth: people treat you differently.
The truth I learned after [big milestone]. ||| Truth I learned after hitting 100K followers: numbers don't equal happiness.
HOOKS;
}
