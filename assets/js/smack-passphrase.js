/**
 * SNAPSMACK - Passphrase Generator
 *
 * Generates memorable 6-word passphrases. Called from snap-in.php
 * and smack-change-password.php via the "Suggest a passphrase" button.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


const SNAP_WORDS = [
    // nouns — creatures
    'badger','hamster','walrus','hedgehog','platypus','narwhal','lobster',
    'ferret','raccoon','possum','armadillo','capybara','sloth','otter',
    'beaver','moose','llama','alpaca','porcupine','lemur','toucan','macaw',
    'quokka','wombat','mongoose','pangolin','aardvark','wolverine','meerkat',
    'flamingo','pelican','albatross','manatee','tapir','gibbon','axolotl',
    'mudskipper','blobfish','tardigrade','mantisshrimp','duckbill','quail',
    // nouns — food & drink
    'pancake','pickle','biscuit','waffle','pretzel','noodle','crumpet',
    'doughnut','burrito','lasagna','focaccia','calzone','gnocchi','tiramisu',
    'cannoli','biscotti','gazpacho','stroganoff','bratwurst','schnitzel',
    'churro','empanada','ceviche','poutine','ramen','dumpling','meatball',
    'coleslaw','corndog','pretzel','hotdog','hoagie','calzone','stromboli',
    // nouns — objects
    'trombone','spatula','kazoo','accordion','catapult','trebuchet','compass',
    'telescope','periscope','ukulele','banjo','theremin','xylophone','tuba',
    'harmonica','didgeridoo','bagpipe','boomerang','hammock','lantern',
    'periscope','megaphone','sousaphone','trampoline','pendulum','pinwheel',
    // nouns — abstract / events
    'catastrophe','debacle','shenanigan','hullabaloo','kerfuffle','brouhaha',
    'fracas','ruckus','hubbub','pandemonium','bonanza','extravaganza',
    'conundrum','paradox','conspiracy','phenomenon','algorithm','paradigm',
    // adjectives
    'floppy','soggy','grumpy','electric','enormous','wobbly','sparkly',
    'fuzzy','slimy','crunchy','bouncy','squiggly','frothy','lumpy','bumpy',
    'jiggly','flaky','crispy','sticky','gooey','fluffy','scraggly','gangly',
    'lanky','plump','hollow','porous','rigid','limp','blazing','frozen',
    'scorching','frigid','tepid','steaming','deranged','baffled','flummoxed',
    'bewildered','perplexed','gobsmacked','startled','frantic','serene',
    'restless','ferocious','magnificent','decrepit','illustrious','baffling',
    'rambunctious','cantankerous','flabbergasted','discombobulated','befuddled',
    'bamboozled','thunderstruck','dumbfounded','mystified','perpendicular',
    // verbs
    'wobble','launch','smash','gobble','tumble','gallop','shuffle','waddle',
    'pounce','lurch','stumble','ramble','dawdle','saunter','swagger','strut',
    'tiptoe','scamper','scramble','clamber','trudge','stomp','trample',
    'bounce','ricochet','careen','swerve','skid','barrel','hurtle',
    'plummet','soar','glide','drift','meander','zigzag','spiral',
    'detonate','combust','sizzle','crackle','rumble','bellow','squawk',
    'gurgle','burble','babble','prattle','jabber','blather','discombobulate',
    // misc colour / nature
    'turquoise','crimson','chartreuse','marigold','obsidian','vermillion',
    'magenta','cerulean','viridian','tangerine','alabaster','mahogany',
    'thunderstorm','avalanche','whirlpool','tornado','blizzard','earthquake',
    'volcano','monsoon','hurricane','tsunami','wildfire','cyclone',
];

function snapGeneratePassphrase(wordCount) {
    wordCount = wordCount || 6;
    const words = [];
    const pool  = [...SNAP_WORDS];
    for (let i = 0; i < wordCount; i++) {
        const idx = Math.floor(Math.random() * pool.length);
        words.push(pool.splice(idx, 1)[0]);
    }
    return words.join('');
}

function snapSuggestPassphrase(targetInputId, displayId) {
    const phrase = snapGeneratePassphrase(6);
    const input  = document.getElementById(targetInputId);
    const display = document.getElementById(displayId);
    if (input)   input.value = phrase;
    if (display) display.textContent = phrase;
}
// ===== SNAPSMACK EOF =====
