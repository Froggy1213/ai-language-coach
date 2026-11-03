<?php

/*
|--------------------------------------------------------------------------
| English scenario catalogue
|--------------------------------------------------------------------------
|
| One entry per role-play scenario, written in the order the topic list shows
| them. ScenarioCatalogue reads this file; `code` is sent to the voice agent
| and stored on `voice_sessions.scenario_code`, so treat codes as stable —
| renaming one orphans the sessions that point at it.
|
| `ai_goal` and `complications` are what turn the scenario into a
| conversation instead of a form: the AI keeps its own goal and introduces
| friction rather than accepting the learner's first answer. Both are
| deliberately not exposed to the client (plan §7).
|
| Every entry is validated when it is loaded: `level` must be A1–C1,
| `register` formal/casual/neutral, `complications` at least two, and
| `useful_phrases` three to five. After editing run
| `php artisan test --filter=Scenario`.
|
*/

return [

    'free_talk' => [
        'title' => 'Free conversation',
        'summary' => 'No script and no agenda — talk about your week, your work, or whatever you bring up.',
        'level' => 'A2',
        'register' => 'neutral',
        'setting' => 'A quiet café after work. Nothing is prepared: the learner chose free conversation instead of a scenario.',
        'ai_role' => "Friendly conversation partner. Curious about the learner's everyday life and easy to talk to.",
        'ai_goal' => 'Find out what the learner actually thinks about something that happened this week, and keep them talking without turning the chat into an interview.',
        'learner_role' => 'You, after a long day at work, with nothing prepared and no particular topic in mind.',
        'learner_goal' => 'Keep a natural conversation going for several minutes without falling back on one-word answers.',
        'complications' => [
            'When the learner gives a one-line answer, pick out one detail and ask why.',
            'Offer a mild opinion they may disagree with — for example that working from home is overrated — then wait for their reaction.',
            'Share something from your own week and leave a pause, so the learner has to ask you a question back.',
        ],
        'opening_line' => 'Hey, good to see you. How was your week — did anything interesting happen?',
        'success_criteria' => 'The learner speaks in full sentences, asks at least one question back, and keeps the thread alive for several turns.',
        'useful_phrases' => [
            'It was a pretty busy week, actually.',
            'That reminds me of something that happened on Monday.',
            'What about you — how was your week?',
            "I'm not sure I agree with that.",
        ],
    ],

    'airport_checkin' => [
        'title' => 'Airport check-in',
        'summary' => 'Check in for a connecting flight, with a bag that is slightly too heavy.',
        'level' => 'A2',
        'register' => 'formal',
        'setting' => 'You are the check-in agent at Haneda airport. The learner is a passenger whose flight to Seoul leaves in forty minutes.',
        'ai_role' => 'Check-in agent. Efficient, friendly, slightly harried — the queue is long.',
        'ai_goal' => 'Get the passenger checked in and their bag tagged before the desk closes. You need their passport and a seat preference.',
        'learner_role' => 'Passenger with one suitcase, a window preference, and a connection in Seoul.',
        'learner_goal' => 'Check in, keep your connection, and find out whether the bag goes all the way.',
        'complications' => [
            'The first seat you offer is a middle seat in the back row.',
            'The bag is two kilos over the limit.',
            'The connection is only fifty minutes — you have to mention it.',
        ],
        'opening_line' => 'Good evening. Passport, please — are you checking a bag today?',
        'success_criteria' => 'Passport shown, seat chosen, bag accepted or paid for, connection confirmed.',
        'useful_phrases' => [
            'I have a connecting flight to Seoul.',
            'Could I have a window seat?',
            'Will my bag go all the way through?',
        ],
    ],

    'business_meeting' => [
        'title' => 'Business meeting',
        'summary' => 'Report a two-day delay on a fixed release date and agree a recovery plan.',
        'level' => 'B1',
        'register' => 'formal',
        'setting' => "The weekly project meeting at a Tokyo software company. The release is fixed for Thursday and the learner's feature is behind.",
        'ai_role' => 'Project lead running the meeting. Calm and organised, but the release date is public and you need a credible answer.',
        'ai_goal' => 'Get a realistic recovery plan for the delayed feature, find out what is actually left, and decide what to cut if needed.',
        'learner_role' => 'The engineer responsible for the export feature, two days behind schedule.',
        'learner_goal' => 'Explain the delay honestly, defend your new estimate, and leave with agreed next steps.',
        'complications' => [
            'Push back on the first new estimate: "Two more days sounds optimistic — what exactly is left?"',
            'Point out that the client demo is on Thursday and ask what could be cut.',
            'Offer to move a colleague onto the task, which the learner has to accept or refuse.',
        ],
        'opening_line' => 'Thanks for making time. Before the agenda — where are we on the export feature?',
        'success_criteria' => 'The delay is explained without blaming anyone, a new date is agreed, and the learner leaves with one concrete next step.',
        'useful_phrases' => [
            "We're running about two days behind on the export feature.",
            'The remaining work is testing and error handling.',
            "I'd rather keep the scope and move the date by two days.",
            "I'll send an update to the client by Wednesday.",
        ],
    ],

    'client_call' => [
        'title' => 'Client call',
        'summary' => 'A long-standing client calls about a slow dashboard and wants a firm date, not an apology.',
        'level' => 'B1',
        'register' => 'formal',
        'setting' => 'A phone call from a client in Singapore to the support team of a Tokyo SaaS company. Their dashboard has been slow since Monday.',
        'ai_role' => 'Long-standing client. Polite, but the delay is costing their team and you want a date rather than an apology.',
        'ai_goal' => 'Get a firm fix date and a workaround you can use today, and put the commitment in writing without damaging the relationship.',
        'learner_role' => 'Customer support engineer who owns this account and knows the fix is still being tested.',
        'learner_goal' => 'Acknowledge the impact, give a realistic timeline, and keep the client calm.',
        'complications' => [
            'Reject a vague answer: "Soon is not a date — my team keeps asking me when."',
            'Say the workaround they were offered does not work for their workflow.',
            'Ask who will inform their management, and whether the commitment can be confirmed in writing.',
        ],
        'opening_line' => "Hello, this is Marcus from Meridian Logistics. I'm calling about the dashboard — it's been slow since Monday and my team is losing patience.",
        'success_criteria' => 'The learner acknowledges the impact, gives a date and a workaround, and the client confirms the plan is acceptable.',
        'useful_phrases' => [
            "Thank you for flagging this, and I'm sorry for the impact on your team.",
            'Let me confirm what we know so far.',
            'We can have a fix deployed by Thursday.',
            "I'll send you a written summary right after this call.",
        ],
    ],

    'job_interview' => [
        'title' => 'Job interview',
        'summary' => 'First-round interview for a project manager role — specific examples, one honest failure, and a question of your own.',
        'level' => 'B1',
        'register' => 'formal',
        'setting' => 'A first-round interview at a foreign company in Tokyo. The learner is applying for a project manager role after several years of coordination work.',
        'ai_role' => 'Hiring manager. Warm but busy; you have heard many general answers and you are listening for specifics.',
        'ai_goal' => 'Find out whether the learner has led a project end to end and what they actually do when a plan goes wrong.',
        'learner_role' => 'Candidate with coordination experience, applying for your first project manager role.',
        'learner_goal' => 'Show concrete experience, ask a real question about the role, and leave a clear impression.',
        'complications' => [
            'Challenge a general answer: "Can you give me a specific example — what did you do yourself?"',
            'Ask about a project that did not go well, and wait through the pause.',
            'Ask why the learner is leaving their current company.',
            'Mention occasional evening calls with the US team and ask whether that is a problem.',
        ],
        'opening_line' => "Thanks for coming in today. To start, could you walk me through what you're doing in your current role?",
        'success_criteria' => 'Answers use concrete examples, the learner asks at least one question, and the reason for the move is clear.',
        'useful_phrases' => [
            'In my current role, I coordinate the release schedule.',
            'One example is a project where we missed the first deadline.',
            'Could you tell me more about what success looks like in the first six months?',
            "I'd like to ask about the team I'd be working with.",
        ],
    ],

    'small_talk_before_meeting' => [
        'title' => 'Small talk before a meeting',
        'summary' => 'Fill the five minutes before a client meeting without letting the conversation die.',
        'level' => 'A2',
        'register' => 'neutral',
        'setting' => 'A meeting room at a client office in Osaka, five minutes before the meeting starts. The learner has just arrived from Tokyo; the AI is the client contact already in the room.',
        'ai_role' => 'Client contact. Friendly and relaxed, making conversation while the rest of the room arrives.',
        'ai_goal' => 'Keep the wait comfortable, find one thing you have in common, and get the learner to carry some of the conversation.',
        'learner_role' => 'Visitor from the Tokyo office, slightly early, holding your presentation materials.',
        'learner_goal' => 'Make a good impression with natural small talk and avoid long silences.',
        'complications' => [
            'Ask an open question that needs more than yes or no: "How was your trip down?"',
            'Mention the weather and the train delay, then leave a pause for the learner to continue.',
            'Ask whether the learner has been to Osaka before, and follow up with a recommendation.',
        ],
        'opening_line' => 'Hi, you must be from the Tokyo team — welcome. Did you come down this morning?',
        'success_criteria' => 'The learner answers in full sentences, asks at least one question back, and keeps the conversation going until the others arrive.',
        'useful_phrases' => [
            'Yes, I took the early shinkansen this morning.',
            'The trip was fine, thanks — a bit crowded, though.',
            'Have you been to this office before?',
            "It's my first time in Osaka, actually.",
        ],
    ],

];
