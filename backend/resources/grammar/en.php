<?php

/*
|--------------------------------------------------------------------------
| English grammar catalogue (A1–C1)
|--------------------------------------------------------------------------
|
| One entry per teachable grammar point, written in teaching order. Together
| with the per-language `uncategorized` sentinel these entries are the
| `grammar_points` table: GrammarPointSeeder upserts them, RoadmapGenerator
| turns the ones at or below a learner's CEFR band into lesson cards.
|
| `code` is matched against LLM output when a mistake is classified (plan §5),
| so treat codes as stable — renaming one orphans the mistakes that point at
| it. Cheat sheets are written in the target language; the pitfalls call out
| the mistakes Russian speakers actually make.
|
| After editing this file run `php artisan db:seed --class=GrammarPointSeeder`
| and `php artisan test --filter=GrammarCatalogueTest`.
|
*/

return [

    // ---------------------------------------------------------------- A1 --

    'present_simple' => [
        'title' => 'Present Simple',
        'category' => 'tenses',
        'level' => 'A1',
        'cheat_sheet' => [
            'rule' => 'Use the present simple for habits, routines, permanent situations and general facts.',
            'formula' => 'I/you/we/they + base verb · he/she/it + base verb + -s · do/does for questions and negatives',
            'examples' => [
                'I get up at seven every morning.',
                'She works in a small clinic near the station.',
                'Water boils at 100 degrees.',
            ],
            'pitfalls' => [
                "Never add -s after I/you/we/they: 'I works' is wrong.",
                "Questions and negatives need do/does: 'Does she work here?', 'He doesn't drive.'",
                'Use it for facts, not for what is happening at this moment — that is the present continuous.',
            ],
        ],
        'practice_prompt' => 'Describe your usual weekday: what you always, usually and never do, and at what time.',
    ],

    'present_continuous' => [
        'title' => 'Present Continuous',
        'category' => 'tenses',
        'level' => 'A1',
        'cheat_sheet' => [
            'rule' => 'Use the present continuous for actions happening now, around now, or already arranged for the near future.',
            'formula' => 'am/is/are + verb-ing',
            'examples' => [
                'I am waiting for the bus.',
                'She is studying for her exams this month.',
                'We are meeting Anna at six tomorrow.',
            ],
            'pitfalls' => [
                "State verbs (know, like, want, need, believe) stay simple: 'I know the answer', not 'I am knowing'.",
                "Do not drop the auxiliary: 'She working' is wrong — say 'She is working'.",
            ],
        ],
        'practice_prompt' => 'Look around you and describe five things that are happening right now, plus one thing you are doing this week.',
    ],

    'past_simple' => [
        'title' => 'Past Simple',
        'category' => 'tenses',
        'level' => 'A1',
        'cheat_sheet' => [
            'rule' => 'Use the past simple for finished actions at a known or finished time in the past.',
            'formula' => 'regular: verb + -ed · irregular: second form (go → went) · questions and negatives: did + base verb',
            'examples' => [
                'I called her yesterday.',
                'We went to Lisbon last summer.',
                "He didn't finish the report.",
            ],
            'pitfalls' => [
                "After did/didn't use the base verb: 'Did you went?' is wrong.",
                "Do not use the present perfect with a finished time: 'I have seen him yesterday' → 'I saw him yesterday'.",
                "The -ed ending is pronounced /t/, /d/ or /ɪd/ — 'worked', 'played', 'wanted'.",
            ],
        ],
        'practice_prompt' => 'Tell the story of your last weekend in five sentences, with time markers: on Saturday morning, then, after that, in the evening.',
    ],

    'be_and_there' => [
        'title' => 'be, there is / there are',
        'category' => 'verbs',
        'level' => 'A1',
        'cheat_sheet' => [
            'rule' => 'Use be to say what something is or where it is, and there is/there are to say that something exists.',
            'formula' => 'am/is/are · was/were · there is + singular · there are + plural',
            'examples' => [
                'She is a nurse.',
                'There is a park behind my house.',
                'There were two messages for you.',
            ],
            'pitfalls' => [
                "Match the verb to the real subject: 'There is two problems' → 'There are two problems'.",
                "Russian speakers often drop be in the present: say 'He is tired', not 'He tired'.",
                "Use it is for weather, time and distance: 'It is cold', not 'There is cold'.",
            ],
        ],
        'practice_prompt' => 'Describe your home room by room: what there is in each one and where the furniture is.',
    ],

    'articles' => [
        'title' => 'a / an / the',
        'category' => 'nouns',
        'level' => 'A1',
        'cheat_sheet' => [
            'rule' => 'Use a/an for one non-specific thing, the for something both speakers can identify, and no article for general plurals and abstract nouns.',
            'formula' => 'a + consonant sound · an + vowel sound · the + specific or already mentioned · zero article for general statements',
            'examples' => [
                'I bought a laptop. The laptop is very light.',
                'She is an engineer.',
                'Cats sleep a lot.',
            ],
            'pitfalls' => [
                "Choose a/an by sound, not spelling: 'an hour', 'a university'.",
                "Do not use the for general statements: 'I like music', not 'I like the music'.",
                'Russian has no articles, so they are the most frequently dropped words in A1 speech — check every noun.',
            ],
        ],
        'practice_prompt' => 'Describe the things on your desk, then say which of them you use every day and why.',
    ],

    'plurals_countability' => [
        'title' => 'Plurals & countable nouns',
        'category' => 'nouns',
        'level' => 'A1',
        'cheat_sheet' => [
            'rule' => 'Countable nouns have a singular and a plural; uncountable nouns have no plural and take a singular verb.',
            'formula' => 'regular plural: + -s/-es · irregular: man → men, child → children · uncountable: money, water, advice, information, furniture',
            'examples' => [
                'There are three chairs in the room.',
                'I need some information about the course.',
                'She gave me two pieces of advice.',
            ],
            'pitfalls' => [
                "Uncountables take no a/an and no number: 'an advice', 'two informations' are wrong — say 'a piece of advice'.",
                "people, police and clothes are plural: 'People are waiting'.",
                "news looks plural but is singular: 'The news is good'.",
            ],
        ],
        'practice_prompt' => 'Say what you bought last week and how much of it: two apples, a bottle of water, some rice.',
    ],

    // ---------------------------------------------------------------- A2 --

    'past_continuous' => [
        'title' => 'Past Continuous',
        'category' => 'tenses',
        'level' => 'A2',
        'cheat_sheet' => [
            'rule' => 'Use the past continuous for an action in progress at a moment in the past, often interrupted by a shorter action.',
            'formula' => 'was/were + verb-ing · with the past simple for the interrupting action',
            'examples' => [
                'I was cooking when you called.',
                'They were living in Berlin in 2020.',
                'What were you doing at nine?',
            ],
            'pitfalls' => [
                "Do not use it for a sequence of finished actions: 'I was waking up, I was having breakfast, I was leaving' should be past simple.",
                "State verbs stay simple: 'I was knowing' is wrong.",
                "The interruption takes the past simple: 'I was reading when he came in'.",
            ],
        ],
        'practice_prompt' => 'Say what you were doing yesterday at three different times, and what interrupted you each time.',
    ],

    'present_perfect' => [
        'title' => 'Present Perfect',
        'category' => 'tenses',
        'level' => 'A2',
        'cheat_sheet' => [
            'rule' => 'Use the present perfect for past actions whose result or time frame reaches the present, and for experiences with no stated time.',
            'formula' => 'have/has + past participle · ever/never/just/already/yet · for + duration · since + starting point',
            'examples' => [
                'I have lost my keys.',
                'She has never been to Japan.',
                'We have lived here since 2019.',
            ],
            'pitfalls' => [
                "Never combine it with a finished time: 'I have seen him yesterday' is wrong.",
                "For a finished period use the past simple: 'I worked there for two years.'",
                "Russian speakers translate 'уже' with the past simple: 'I already did it' → 'I have already done it'.",
            ],
        ],
        'practice_prompt' => 'Three things you have never done, two you have just done, and how long you have known your closest friend.',
    ],

    'future_going_to' => [
        'title' => 'be going to',
        'category' => 'tenses',
        'level' => 'A2',
        'cheat_sheet' => [
            'rule' => 'Use be going to for plans you have already decided and for predictions based on what you can see.',
            'formula' => 'am/is/are + going to + base verb',
            'examples' => [
                'I am going to start a course in October.',
                'Look at those clouds — it is going to rain.',
                'We are not going to move this year.',
            ],
            'pitfalls' => [
                "Keep the base verb after going to: 'I am going to started' is wrong.",
                "A decision made while speaking takes will: 'OK, I'll take the early train.'",
            ],
        ],
        'practice_prompt' => 'Describe your plans for the next month: what you are going to do, where you are going to go, and why.',
    ],

    'future_will' => [
        'title' => 'will for the future',
        'category' => 'tenses',
        'level' => 'A2',
        'cheat_sheet' => [
            'rule' => 'Use will for decisions made at the moment of speaking, offers, promises and neutral predictions.',
            'formula' => "will + base verb · won't + base verb · Shall I/we …?",
            'examples' => [
                "I'll take the early train, then.",
                'I will help you with that.',
                'It will probably be cold tomorrow.',
            ],
            'pitfalls' => [
                "No will after when/if/as soon as in a future sentence: 'When I will arrive' → 'When I arrive'.",
                "will has no -s and no to: 'He wills go', 'I will to go' are wrong.",
                'For a plan already made, going to sounds more natural than will.',
            ],
        ],
        'practice_prompt' => 'Make five predictions about your city in ten years and three promises for this week.',
    ],

    'comparatives_superlatives' => [
        'title' => 'Comparatives & superlatives',
        'category' => 'adjectives',
        'level' => 'A2',
        'cheat_sheet' => [
            'rule' => 'Compare two things with the comparative and three or more with the superlative.',
            'formula' => 'short adjectives: -er/-est · longer ones: more/most + adjective · equality: as … as',
            'examples' => [
                'This flat is bigger than the old one.',
                'She is the most patient teacher I know.',
                'Today is not as cold as yesterday.',
            ],
            'pitfalls' => [
                "Never use both endings: 'more bigger' is wrong.",
                'Use than after a comparative and the before a superlative.',
                'good → better → the best; bad → worse → the worst; far → further → the furthest.',
            ],
        ],
        'practice_prompt' => 'Compare two cities you know well: transport, prices, weather and people.',
    ],

    'modals_ability_permission' => [
        'title' => 'can / could / may',
        'category' => 'modals',
        'level' => 'A2',
        'cheat_sheet' => [
            'rule' => 'Use can and could for ability, and can/could/may for permission and polite requests.',
            'formula' => 'can/could + base verb · be able to for other tenses · May I …? · Could you …?',
            'examples' => [
                "I can swim, but I couldn't ride a bike until I was nine.",
                'Could you send me the file?',
                'May I open the window?',
            ],
            'pitfalls' => [
                "No to after a modal: 'I can to swim' is wrong.",
                "No -s and no do-support: 'He cans', 'Does he can' are wrong.",
                "could is general past ability; for one successful action use was able to: 'I was able to finish it yesterday'.",
            ],
        ],
        'practice_prompt' => 'Say what you can do well, what you could not do five years ago, and make three polite requests.',
    ],

    'prepositions_time_place' => [
        'title' => 'Prepositions of time & place',
        'category' => 'prepositions',
        'level' => 'A2',
        'cheat_sheet' => [
            'rule' => 'Use in for months, years, seasons and enclosed spaces; on for days, dates and surfaces; at for clock times, points and events.',
            'formula' => 'in July / in 2026 / in the room · on Monday / on 5 May / on the wall · at 8 pm / at the door / at a party',
            'examples' => [
                'The lesson starts at seven on Tuesday.',
                'I was born in 1998 in a small town.',
                'The keys are on the table.',
            ],
            'pitfalls' => [
                'Fixed phrases break the rule: at night, in the morning, on Friday night.',
                "Do not translate Russian 'в' with one preposition — decide whether it is a time, a surface or a place.",
            ],
        ],
        'practice_prompt' => 'Describe your next week using in, on and at at least six times.',
    ],

    // ---------------------------------------------------------------- B1 --

    'present_perfect_continuous' => [
        'title' => 'Present Perfect Continuous',
        'category' => 'tenses',
        'level' => 'B1',
        'cheat_sheet' => [
            'rule' => 'Use it for an activity that started in the past and is still going on, or has just stopped with a visible result.',
            'formula' => 'have/has been + verb-ing · for + duration · since + starting point',
            'examples' => [
                'I have been learning English for six years.',
                "She has been working since eight o'clock.",
                'Why are you tired? — I have been running.',
            ],
            'pitfalls' => [
                "Non-continuous verbs rarely take it: 'I have been knowing him' is wrong.",
                "Use the present perfect for a completed amount: 'I have written three reports today'.",
            ],
        ],
        'practice_prompt' => 'Talk about your habits: how long you have been doing them and what you have achieved so far.',
    ],

    'past_perfect' => [
        'title' => 'Past Perfect',
        'category' => 'tenses',
        'level' => 'B1',
        'cheat_sheet' => [
            'rule' => 'Use it for the earlier of two past actions, and for a state that was already true before a past moment.',
            'formula' => 'had + past participle · with before/after/already/by the time',
            'examples' => [
                'The film had already started when we arrived.',
                'She had never seen snow before that winter.',
                'By 2020 he had changed jobs twice.',
            ],
            'pitfalls' => [
                'If the order is already clear from before/after, the past simple is enough.',
                "Keep the past participle: 'had went' is wrong.",
            ],
        ],
        'practice_prompt' => 'Describe a day when everything went wrong: what had already happened before you even left the house.',
    ],

    'first_conditional' => [
        'title' => 'First conditional',
        'category' => 'conditionals',
        'level' => 'B1',
        'cheat_sheet' => [
            'rule' => 'Use it for a real, likely future condition and its result.',
            'formula' => "If + present simple, will/won't + base verb",
            'examples' => [
                'If it rains, we will stay at home.',
                'If you finish early, call me.',
                "I won't go unless you come with me.",
            ],
            'pitfalls' => [
                "Never use will in the if-clause: 'If it will rain' is wrong.",
                "unless already means 'if not' — 'unless you don't come' is a double negative.",
                "The result clause can take a modal or an imperative: 'If you see him, tell him.'",
            ],
        ],
        'practice_prompt' => 'Give five realistic conditions for the next month: if I pass the exam, if the weather is good, and so on.',
    ],

    'second_conditional' => [
        'title' => 'Second conditional',
        'category' => 'conditionals',
        'level' => 'B1',
        'cheat_sheet' => [
            'rule' => 'Use it for an unreal or unlikely present or future situation, and for polite hypothetical advice.',
            'formula' => 'If + past simple, would + base verb',
            'examples' => [
                'If I had more time, I would take up the guitar.',
                'What would you do if you lost your job?',
                'If I were you, I would apologise.',
            ],
            'pitfalls' => [
                "The if-clause takes the past simple, not would: 'If I would have time' is wrong.",
                "were is standard for every person in this pattern: 'If I were you'.",
            ],
        ],
        'practice_prompt' => 'Three things you would change about your job, your city and your daily routine if you could.',
    ],

    'passive_present_past' => [
        'title' => 'Passive: present & past',
        'category' => 'passive',
        'level' => 'B1',
        'cheat_sheet' => [
            'rule' => 'Use the passive when the action matters more than the doer, or the doer is unknown or obvious.',
            'formula' => 'am/is/are + past participle · was/were + past participle · by + agent',
            'examples' => [
                'The road is closed for repairs.',
                'My bike was stolen last week.',
                'English is spoken all over the world.',
            ],
            'pitfalls' => [
                "Keep the right participle: 'The letter was wrote' is wrong.",
                "Only transitive verbs take the passive: 'I was arrived' is impossible.",
                'Do not add by + agent when it is obvious or unknown — it defeats the point.',
            ],
        ],
        'practice_prompt' => 'Describe how something you own was made, or how a local event is organised, without naming the doer.',
    ],

    'relative_clauses' => [
        'title' => 'Defining relative clauses',
        'category' => 'clauses',
        'level' => 'B1',
        'cheat_sheet' => [
            'rule' => 'Use a relative clause to say which person or thing you mean without starting a new sentence.',
            'formula' => 'who for people · which for things · that for both · whose for possession · where for places',
            'examples' => [
                'The woman who called you is my manager.',
                'This is the book that changed my mind.',
                'That is the café where we met.',
            ],
            'pitfalls' => [
                "Do not repeat the object with a pronoun: 'the film that I saw it' is wrong.",
                'Do not drop who/which when it is the subject of the clause.',
                "Non-defining clauses take commas and never that: 'My brother, who lives in Rome, …'.",
            ],
        ],
        'practice_prompt' => 'Describe three people or things that matter to you, using who, which and whose.',
    ],

    'modals_obligation_advice' => [
        'title' => 'must / have to / should',
        'category' => 'modals',
        'level' => 'B1',
        'cheat_sheet' => [
            'rule' => "Use must and have to for obligation, mustn't for prohibition, don't have to for no obligation, and should for advice.",
            'formula' => "must + base verb · have to + base verb · should + base verb · don't have to + base verb",
            'examples' => [
                'You must wear a helmet on site.',
                'I have to finish this by Friday.',
                'You should get more sleep.',
            ],
            'pitfalls' => [
                "mustn't and don't have to are opposites: no obligation is not prohibition.",
                'must has no past or future — use had to, will have to.',
                "must is for the speaker's own strong feeling; have to is for an external rule.",
            ],
        ],
        'practice_prompt' => 'List the rules of your workplace or your city, then give a friend three pieces of advice.',
    ],

    'reported_speech' => [
        'title' => 'Reported speech',
        'category' => 'clauses',
        'level' => 'B1',
        'cheat_sheet' => [
            'rule' => 'When you report what someone said, move the tense one step back and adjust pronouns and time words.',
            'formula' => 'said (that) + shifted tense · told + person · asked if/whether · asked + wh-word',
            'examples' => [
                'She said she was moving to Warsaw.',
                'He told me he had finished the report.',
                'They asked whether I could stay longer.',
            ],
            'pitfalls' => [
                "told needs a person: 'He told that …' is wrong — say 'He told me that …'.",
                "If the reporting verb is present, the tense does not shift: 'She says she is busy'.",
                'Adjust time and place words: today → that day, tomorrow → the next day, here → there.',
            ],
        ],
        'practice_prompt' => 'Report a conversation you had today: what the other person said, asked and promised.',
    ],

    // ---------------------------------------------------------------- B2 --

    'third_conditional' => [
        'title' => 'Third conditional',
        'category' => 'conditionals',
        'level' => 'B2',
        'cheat_sheet' => [
            'rule' => 'Use it for an unreal past situation and its imaginary result — the condition can no longer be changed.',
            'formula' => 'If + had + past participle, would have + past participle',
            'examples' => [
                'If I had left earlier, I would not have missed the train.',
                'She would have passed if she had read the question properly.',
                "I wouldn't have known if you hadn't told me.",
            ],
            'pitfalls' => [
                "There is no 'had have': 'If I had have known' is wrong.",
                'Write would have, not would of.',
                'When the two time frames differ, use a mixed conditional instead.',
            ],
        ],
        'practice_prompt' => 'Take one decision from last year and describe two ways it could have turned out differently.',
    ],

    'passive_perfect_modal' => [
        'title' => 'Passive: perfect & modal',
        'category' => 'passive',
        'level' => 'B2',
        'cheat_sheet' => [
            'rule' => 'Build more complex passives with perfect and modal auxiliaries when the focus stays on the action.',
            'formula' => 'have/has been + past participle · had been + past participle · modal + be + past participle',
            'examples' => [
                'The report has been sent to all departments.',
                'The bridge had been repaired before the storm.',
                'The form must be signed by both parents.',
            ],
            'pitfalls' => [
                "Keep the whole auxiliary chain: 'The form must signed' is wrong.",
                "With make, see and hear the passive takes a full infinitive: 'He was made to wait.'",
            ],
        ],
        'practice_prompt' => 'Explain a process in your field from the point of view of the object: what has been checked, what must be approved.',
    ],

    'gerunds_infinitives' => [
        'title' => 'Gerunds & infinitives',
        'category' => 'verbs',
        'level' => 'B2',
        'cheat_sheet' => [
            'rule' => 'Some verbs take a gerund, some take an infinitive, and a few take both with a change of meaning.',
            'formula' => 'enjoy/avoid/suggest/recommend + verb-ing · decide/offer/promise/refuse + to + verb · stop/remember/try + either',
            'examples' => [
                'I enjoy working from home.',
                'She decided to apply for the scholarship.',
                'He stopped smoking last year. / He stopped to smoke.',
            ],
            'pitfalls' => [
                "After a preposition always use the gerund: 'interested in learning', never 'interested in to learn'.",
                'stop doing = end the activity; stop to do = pause in order to do something else.',
                'look forward to + verb-ing, despite + verb-ing.',
            ],
        ],
        'practice_prompt' => 'Describe what you enjoy doing, what you avoid, and what you have decided to change.',
    ],

    'future_perfect_continuous' => [
        'title' => 'Future perfect & future continuous',
        'category' => 'tenses',
        'level' => 'B2',
        'cheat_sheet' => [
            'rule' => 'Use the future continuous for an action in progress at a future moment, and the future perfect for something completed before it.',
            'formula' => 'will be + verb-ing · will have + past participle · by + time',
            'examples' => [
                'This time tomorrow I will be flying to Rome.',
                'By December I will have finished the course.',
                'Will you be using the car tonight?',
            ],
            'pitfalls' => [
                "Use by, not until, with the future perfect: 'by Friday' means no later than Friday.",
                "After when/if the present tense replaces the future form: 'When I have finished'.",
            ],
        ],
        'practice_prompt' => 'Project yourself into next year: what you will be doing in June and what you will have achieved by then.',
    ],

    'wish_if_only' => [
        'title' => 'wish / if only',
        'category' => 'clauses',
        'level' => 'B2',
        'cheat_sheet' => [
            'rule' => "Use wish and if only for a regret or a desire that the present, the past or someone's behaviour were different.",
            'formula' => "wish + past simple (present) · wish + had + past participle (past) · wish + would (other people's habits)",
            'examples' => [
                'I wish I spoke Japanese.',
                'She wishes she had taken the offer.',
                'I wish you would stop interrupting me.',
            ],
            'pitfalls' => [
                "A present regret takes the past form: 'I wish I knew', not 'I wish I know'.",
                "wish + would is never about yourself: 'I wish I would' is wrong.",
                "hope takes an ordinary tense: 'I hope you feel better.'",
            ],
        ],
        'practice_prompt' => 'Three things you wish were different now, one past regret, and one habit you want someone else to change.',
    ],

    'concession_linkers' => [
        'title' => 'although / despite / however',
        'category' => 'discourse',
        'level' => 'B2',
        'cheat_sheet' => [
            'rule' => 'Use concession linkers to set an unexpected fact against the main statement.',
            'formula' => 'although/even though + clause · despite/in spite of + noun or verb-ing · however + new sentence with a comma',
            'examples' => [
                'Although the price was high, the flat sold quickly.',
                'Despite working overtime, he missed the deadline.',
                'The plan was expensive. However, it was approved.',
            ],
            'pitfalls' => [
                "despite is not followed by a clause: 'despite it was raining' → 'despite the rain' or 'although it was raining'.",
                'however is not a conjunction — never join two clauses with a comma alone.',
                'even though is stronger than although.',
            ],
        ],
        'practice_prompt' => 'Describe a decision you disagreed with, giving both sides with although, despite and however.',
    ],

    // ---------------------------------------------------------------- C1 --

    'mixed_conditionals' => [
        'title' => 'Mixed conditionals',
        'category' => 'conditionals',
        'level' => 'C1',
        'cheat_sheet' => [
            'rule' => 'Mix the time frames of the two halves when the condition and its result belong to different times.',
            'formula' => 'If + had + past participle, would + base verb (past cause, present result) · If + past simple, would have + past participle (present trait, past result)',
            'examples' => [
                'If I had taken that job, I would be living in Berlin now.',
                "If she weren't so stubborn, she would have apologised yesterday.",
                'If we had invested then, we would be debt-free today.',
            ],
            'pitfalls' => [
                'Keep each half in the pattern its own time frame requires — do not put would have on both sides.',
                'Use a mixed form only when the time frames genuinely differ; otherwise a standard conditional is clearer.',
            ],
        ],
        'practice_prompt' => 'Explain how one earlier decision still shapes your present, and how one present habit shaped a past outcome.',
    ],

    'inversion_emphasis' => [
        'title' => 'Inversion after negative adverbials',
        'category' => 'advanced',
        'level' => 'C1',
        'cheat_sheet' => [
            'rule' => 'After a fronted negative or restrictive adverbial, invert the subject and the auxiliary for emphasis.',
            'formula' => 'Never/Rarely/Seldom/Little + auxiliary + subject · No sooner had … than · Not only … but also · Hardly had … when',
            'examples' => [
                'Never have I seen such a mess.',
                'No sooner had we sat down than the alarm went off.',
                'Not only did she finish first, but she also set a record.',
            ],
            'pitfalls' => [
                "The auxiliary moves, the main verb does not: 'Never I have seen' is wrong.",
                'No sooner pairs with than, hardly with when.',
                "Only + time or place phrase inverts too: 'Only then did I understand.'",
            ],
        ],
        'practice_prompt' => 'Produce five emphatic sentences about your career using never, rarely, no sooner, not only and only then.',
    ],

    'cleft_sentences' => [
        'title' => 'Cleft sentences',
        'category' => 'advanced',
        'level' => 'C1',
        'cheat_sheet' => [
            'rule' => 'Split a sentence into two clauses to focus attention on one piece of information.',
            'formula' => 'It is/was + focus + that/who … · What + clause + is/was … · The reason why … is that …',
            'examples' => [
                'It was the silence that worried me.',
                'What I need is a week off.',
                'The reason we left early was that the heating had failed.',
            ],
            'pitfalls' => [
                "Agree the verb with the focused noun: 'It is the delays that annoy me.'",
                "The what-clause keeps statement order: 'What do I need is' is wrong.",
                'Clefts are for emphasis — a paragraph of them reads as heavy.',
            ],
        ],
        'practice_prompt' => 'Answer three questions emphatically: what surprised you, who helped you most, and why you chose your field.',
    ],

    'participle_clauses' => [
        'title' => 'Participle clauses',
        'category' => 'clauses',
        'level' => 'C1',
        'cheat_sheet' => [
            'rule' => 'Replace a full clause with a participle when its subject is the same as the main clause, to make writing tighter.',
            'formula' => 'Having + past participle (earlier action) · verb-ing (same time or reason) · past participle (passive meaning)',
            'examples' => [
                'Having read the contract, she refused to sign.',
                'Walking home, I noticed the shop was closed.',
                'Written in 1925, the novel still feels modern.',
            ],
            'pitfalls' => [
                "The implied subject must match the main clause, or the sentence dangles: 'Walking home, the rain started' is wrong.",
                'Use having + participle when one action clearly finishes before the other.',
                'One participle clause per sentence is usually enough.',
            ],
        ],
        'practice_prompt' => 'Summarise your last project in four sentences built on participle clauses.',
    ],

    'subjunctive_mandative' => [
        'title' => 'Mandative subjunctive',
        'category' => 'advanced',
        'level' => 'C1',
        'cheat_sheet' => [
            'rule' => 'After verbs and adjectives of demand, suggestion and importance, the that-clause takes the base form of the verb.',
            'formula' => 'suggest/insist/recommend/demand + that + subject + base verb · It is essential/vital that + subject + base verb',
            'examples' => [
                'The board insisted that he resign.',
                'I recommend that she be informed at once.',
                'It is essential that every applicant submit two references.',
            ],
            'pitfalls' => [
                "The third person keeps the base form: 'that he resign' — an -s turns a demand into a report.",
                "Negatives take not without do: 'that he not resign'.",
                "Where the subjunctive feels stiff, the should-paraphrase is always safe: 'that he should resign'.",
            ],
        ],
        'practice_prompt' => 'Write five formal recommendations for your company using insist, recommend, demand and It is vital that.',
    ],

];
