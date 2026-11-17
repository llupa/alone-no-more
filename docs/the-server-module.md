# mod-anm-bridge — the C++ half

Everything else in this project talks to AzerothCore from PHP, over SOAP and MySQL. Some things
cannot be done that way at all, and this module does exactly those.

It is deliberately small. Each piece below exists because something was tried from outside
first and could not work — the reasons are recorded here because they are not obvious from the
code, and because several of them cost days.

---

## 1. Capturing chat

The core's `chat.system` logger records channel joins, leaves and ownership changes, and never
message bodies. There is no log to tail.

So the module hooks `PlayerScript`'s `OnPlayerCanUseChat` family — which fires for say, yell,
whisper, party, guild and channel, with the full context — and appends to `anm_chat_event`. All
five overloads return `true`: it is a listener, not a censor.

## 2. Making a bot speak

No core or playerbot command makes a bot say arbitrary text, and a PHP process cannot touch a
`Player` object. The module drains an `anm_outbox` table on the world tick, every 500 ms, and
turns rows into `say`, `yell`, `whisper`, `party` and `guild`.

Queueing rather than calling also means a reply that arrives while a bot is logging out fails
one row instead of breaking a conversation.

## 3. Logging bots in and out

`login` and `logout` call `AddPlayerBot` / `LogoutPlayerBot` on the random bot manager. This is
what makes herding possible without teleport artefacts: an offline character can be moved freely
because nobody can see it, so it simply exists where we put it when it logs in.

## 4. Attacking something the player has not selected

mod-playerbots can only attack what the *master* has selected. A player who says "attack the
kobolds" has selected nothing, so the bot stood there while the companion cheerfully answered
"right, I'm on it".

`FindAttackable` searches the bot's surroundings by name, word by word — "diseased wolf" has to
find "Diseased Young Wolf", and a plain substring does not, because the real name has a word in
the middle. Three distinct failures are reported back, because they mean different things to
whoever asked: nothing by that name, something by that name that cannot be fought, or nothing in
reach at all.

It also writes the chosen guid into the bot's own `prioritized targets` value. Starting the
fight is not enough on its own — without that, the combat engine drops back to idle on the next
tick and undoes it.

## 5. Leading the way

The longest story here, and the reason the module exists in its current shape.

Walking a companion to an NPC was attempted six times through mod-playerbots' chat commands and
failed six times, always past about 150 yards. That number is `Playerbots.ReactDistance`, and it
is a leash on every route in from outside:

- `ReturnToStayPositionAction::isPossible()` discards a stay anchor beyond it and silently
  re-anchors the bot where it stands;
- `GoAction`'s coordinate form refuses the same distance outright with "It is too far away".

Three further traps, each of which looked like the bug at the time:

- **`go travel <name>` reports success when it has failed.** If the travel system does not
  recognise the name — and it does not, for most NPCs — it whispers "Clearing travel target",
  clears the target and returns `true`. Nothing throws, so a fallback never runs.
- **Repeated identical move orders are dropped.** `MoveTo` begins with `IsDuplicateMove`, so
  re-sending the same destination is a no-op that looks like effort.
- **Combat erases the destination.** `ChangeEngineOnNonCombat()` resets the stay position every
  time a bot leaves combat. One wandering boar stranded a walk permanently.

So the destination lives in the module's own memory, where the bot's AI cannot clear it, and a
fight on the way is just a pause. The movement itself is mod-playerbots' own
`MovementAction::MoveTo`, reached through a four-line subclass because it is protected and its
constructor is not. That is worth the trick: it resolves ground height with `GetMapHeight`
rather than guessing, searches several heights for a route, and with `normal_only` accepts only
`PATHFIND_NORMAL` or `PATHFIND_INCOMPLETE`.

That last flag matters more than it looks. `PathGenerator` does not fail when there is no route
— it returns `PATHFIND_SHORTCUT`, documented in its own enum as *"travel through obstacles,
terrain, air"*. Followed literally, that walks a companion through a hillside. Ours ended up
under the world twice before this was understood, the second time far enough to be dropped from
it, and said so better than the commit message did: *"I walked as I always do, and I cannot say
how I ended up beneath the earth."*

One more, found the same way: `stay` records two *positions*, not just a strategy, and
`ReturnStrategy` walks a bot back to the second one for as long as it is set. A player who said
"wait here" before asking to be led pinned their companion to that spot, and nothing afterwards
released it. Starting a walk now clears both, exactly as `FollowChatShortcutAction` does — which
is why saying "follow me" always appeared to fix things.

Cities are refused rather than attempted. The mesh there has doors, lifts and stacked floors,
and a companion that cannot get out of Stormwind should say so rather than stand still
apologising.

## 6. Recording what it says itself

Chat reaches `anm_chat_event` through `OnPlayerCanUseChat`, which fires when a *player* speaks.
Say, yell and whisper from a bot go through the normal chat path and are caught by it. A party
or guild message is not: the module builds a packet and broadcasts it to the group, which never
passes the hook.

The consequence was invisible until a session was captured and read back: every question the
player asked was there, and not one reply. Forty-five minutes of a man apparently talking to
himself.

So those two channels are recorded here, deliberately *after* the broadcast, so that what is
written down is what was actually delivered rather than what was intended.

## 7. Milestones

`OnPlayerLevelChanged` and `OnPlayerAchievementComplete` write to `anm_milestone` as they
happen. Polling cannot do this: two levels in one tick would look like one, and an achievement's
name is never written to the characters database at all.

---

## Building it

See [running against a real server](real-server.md), step 2. In short: symlink it into
`modules/`, re-run `cmake`, rebuild the worldserver. It creates its own tables on first start.

## Reading it

`server/mod-anm-bridge/src/AnmBridge.cpp` is about five hundred lines and is commented in the
same spirit as this page — the comments explain the reasons, not the syntax.
