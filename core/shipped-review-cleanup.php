<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/** Exact paths and content hashes shipped in the published 1009D and 1010D ZIPs. */
const SNAPSMACK_SHIPPED_REVIEW_FILES = [
    '_review/alfred-launch-readiness/current-alfred/archive-1024.png' => ['9b75bcb5fa1315e0a7a7e4d9dc4918ee7e68f40e6ed5a58990edb1ed5747100a'],
    '_review/alfred-launch-readiness/current-alfred/archive-1440.png' => ['e9c687a6adebd4ef46a3bd74379a4211f049292235f45d3d07023648800148e7'],
    '_review/alfred-launch-readiness/current-alfred/archive-1920.png' => ['967a296d46435fe08685b1d814ea1d0ad6adc51b6beeaa0cdd3a9136fc0aa621'],
    '_review/alfred-launch-readiness/old-hitchcock/archive-1024.jpg' => ['558158fe65c3c79b785b9fbf57f8177ba715e26b9a750983d7d607bddeb24821'],
    '_review/alfred-launch-readiness/old-hitchcock/archive-1920.jpg' => ['273d32155273e3ac5a7de75ceb1e0e562bf0d8927352fb2f4c2c8f2071609653'],
    '_review/alfred-launch-readiness/old-hitchcock/home-1024.jpg' => ['67c0d4035313ef27c25669e84e7b0f9c574655acd7cde164031490c708b0dbd9'],
    '_review/alfred-launch-readiness/old-hitchcock/home-1920.jpg' => ['7939ff1b3aff31d81690d6ee70bbcef7279375138fc2a09adde241e781045784'],
    '_review/alfred-launch-readiness/old-hitchcock/solo-1024.jpg' => ['75b70e73a650c9abf864bf0ae2ab32fdb87e82eaaf6038f4a4db7554a0669704'],
    '_review/alfred-launch-readiness/old-hitchcock/solo-1920.jpg' => ['a6fc0eca10c8675b2fd6bbb64de223873e3e8b5ced2b7a0ae4783c1043cfa1d1'],
    '_review/onyx-1024-overflow/after-onyx-archive-1024.png' => ['28185fc8ce29cab0bc181ed9bb3ff483cdcc607d265c3e30cc1eb55e3c433c5b'],
    '_review/onyx-1024-overflow/after-onyx-archive-1440.png' => ['b59b46d032c4947da23b0986502bdb977027d8437f685aa7063e10b6cadac2b8'],
    '_review/onyx-1024-overflow/after-onyx-archive-1920.png' => ['b6d03ac14ecb17ab941d04d318633cd92ce74b1e4ea476683276f76ec1391e21'],
    '_review/onyx-1024-overflow/after-onyx-landing-1024.png' => ['919c3b1333fd245b54e835852856d5a2cc1c073ae644d81fbd469c3dcda3e30d'],
    '_review/onyx-1024-overflow/after-onyx-landing-1440.png' => ['d39bb0ea888a11a453b5a3b43d0a173506f452bb4b90e10b5e722fa77a75519f'],
    '_review/onyx-1024-overflow/after-onyx-landing-1920.png' => ['c8b5adc8f8792a6b6bf76a4c0748933884f2a1877c6c5280b3927acf519abd13'],
    '_review/onyx-1024-overflow/fixture-before-onyx-landing-1024.png' => ['4e9606bf5d6454c7bef80157cb981eaaf44adcb4f5d774e86999bbfa76b6bbaa'],
    '_review/ss1563-navigation-current/alfred-1024-child.png' => ['27ae19a83c4276691ef389e0af263f2b07f2c021d4a048ffa0f7e2bbd26b4a97'],
    '_review/ss1563-navigation-current/alfred-1024-current.png' => ['e0b6932a01e06fd9e363dc4523c3649131ae6b740ccf963d24f456008e14bbcc'],
    '_review/ss1563-navigation-current/alfred-1024-no-match.png' => ['c5317997b82e736c42af6367922ade42a290168325629bd7e5eb2baa0a7237cc'],
    '_review/ss1563-navigation-current/alfred-1024-non-current.png' => ['23b7efe811aae6fc3fc2fc47e93a6cd3ec8146c8aa7e19d7367ae2a42c332501'],
    '_review/ss1563-navigation-current/alfred-1440-child.png' => ['98f24f1fa7061c50c7811c751f5a95f82ca99fa4b8c2419d1ecc552c8bd5c5d6'],
    '_review/ss1563-navigation-current/alfred-1440-current.png' => ['887e4d3ef51b72e7a268f1fbe4047c61232f796f9355c6972d8d02d9dcc506f0'],
    '_review/ss1563-navigation-current/alfred-1440-no-match.png' => ['c8361ad49d15f8e4d81094292ac0aa30216f63efa75cb061bfbd483532765a00'],
    '_review/ss1563-navigation-current/alfred-1440-non-current.png' => ['763855f68cb2ee449ef071b4da59f0b67a1b40c8d1792e86e34d0cf44e60e3f7'],
    '_review/ss1563-navigation-current/alfred-1920-child.png' => ['bd8af1e386ae2affe74f07aa35a22c8c0fd0f778f79bef1f46a8a464c55d7c6d'],
    '_review/ss1563-navigation-current/alfred-1920-current.png' => ['c8b05166ee8a60322443e4eaabd87f34b8619fdb85c8ba761ebb46946ab967d1'],
    '_review/ss1563-navigation-current/alfred-1920-no-match.png' => ['dccbf26cf3f55bc9e8c8e5aebebc8fb124e294924ee148b70cc02f7acf75e878'],
    '_review/ss1563-navigation-current/alfred-1920-non-current.png' => ['5c4dbb3006e4247d786ab37926a30ae24421154bc7d1e4f412bfd8a904501971'],
    '_review/ss1563-navigation-current/heuristic-1024-child.png' => ['db3a916ae689c6ad74c7ade820d620975b05f01d2cb7e8da9ce9538a791d7d90'],
    '_review/ss1563-navigation-current/heuristic-1024-current.png' => ['0a86f3af20ef6fa67f1c4f7b77e2a9550ff600d977baf1a0568991359a5d7e70'],
    '_review/ss1563-navigation-current/heuristic-1024-no-match.png' => ['6ec0783915b625f5378b9cf38729b0a8fce215c17cc98fc585680e6005d8bc04'],
    '_review/ss1563-navigation-current/heuristic-1024-non-current.png' => ['2de3997cd7632a2a6c4b03dcf368e725c0b11a9d5ec36a02c16f2b1b80801159'],
    '_review/ss1563-navigation-current/heuristic-1440-child.png' => ['26d5f80e3ec6f0a107d1cee54cac0e99c6f318f24cd4a46837ad7e7641b8c649'],
    '_review/ss1563-navigation-current/heuristic-1440-current.png' => ['5ff49691d2579610cbc648dc94e83ee65b97b760f7f50cc4b6aa90e869b2f09b'],
    '_review/ss1563-navigation-current/heuristic-1440-no-match.png' => ['77cb45501f91b7e4874aa180628661f48bf9101dea2ad3c3fd415fb2fc7ce90f'],
    '_review/ss1563-navigation-current/heuristic-1440-non-current.png' => ['9b23319d365ae876c52de544894eb6034ee164dd487a7df6385d00a681d5e18d'],
    '_review/ss1563-navigation-current/heuristic-1920-child.png' => ['537745370543d7528b248e7660ea2495428c1c1d6b435830b9c7473dc9105e3b'],
    '_review/ss1563-navigation-current/heuristic-1920-current.png' => ['0c48fbc54200460f64d1383779deefbd20489f237df364dc87d536e0fc27fbca'],
    '_review/ss1563-navigation-current/heuristic-1920-no-match.png' => ['a110bb15e6f1f90d96b544bc019d2b31b6085e21406a61fb0d97059048a1c1b9'],
    '_review/ss1563-navigation-current/heuristic-1920-non-current.png' => ['16560813a7e4c603b204209e9b55e5178b53aab7fe49a5e6e3263997333c7ef2'],
    '_review/ss1563-navigation-current/onyx-1024-child.png' => ['605ebc28cb0a5a4a4c898906a1596f3e518fbcb2ed9078f9aa8f32cbd9ba4015'],
    '_review/ss1563-navigation-current/onyx-1024-current.png' => ['3bb95448d0717693275359bee682c248a37dfb8c6c199e1b562a92054c504601'],
    '_review/ss1563-navigation-current/onyx-1024-no-match.png' => ['91c8c3c0cd33d10523b3265593e73fe31f4ff48f697b109a82877bc7c2a75251'],
    '_review/ss1563-navigation-current/onyx-1024-non-current.png' => ['7c531deb75b1e8a4ce6943b443f6d30cd343783e9d8f4ec01f6229394c4b6988'],
    '_review/ss1563-navigation-current/onyx-1440-child.png' => ['d3c704efe7470ad5b4a3b2fd4629ecee64b8a1eea037cbd5471a53f79a487b21'],
    '_review/ss1563-navigation-current/onyx-1440-current.png' => ['c285de6c304501cb411f51e08e09e2ab0c3db703de3afcae552ced93adb51fc0'],
    '_review/ss1563-navigation-current/onyx-1440-no-match.png' => ['5d48632ef0117cd7a48efde11f141d3ab92dc99ca6171b4fb170478b3baa714a'],
    '_review/ss1563-navigation-current/onyx-1440-non-current.png' => ['817ab7c97a085413e8df2e3d711bd4f7a62376378f2dc989e6bdebfa55f1158b'],
    '_review/ss1563-navigation-current/onyx-1920-child.png' => ['ce9d9b2e7c1cf16a208a4826301ff47ccd0790858088afb384d53182830e7b50'],
    '_review/ss1563-navigation-current/onyx-1920-current.png' => ['61f295244d7bf3c451e13d6537c5db1a45910565322a91660a964d96b40ffbd1'],
    '_review/ss1563-navigation-current/onyx-1920-no-match.png' => ['0b335fb88b2000de13b6b12ed0ee5eb3f58857c681e5ff7cf85f6fc17c1e205a'],
    '_review/ss1563-navigation-current/onyx-1920-non-current.png' => ['13cccd27c9be712f06bd22028c978b0f40428621f8db029472c8815c55746b2f'],
    '_review/ss1563-navigation-current/telegram-1024-child.png' => ['376981f5d62a32350234dedd6e53e1a16cd7cdea4e64f8987f4edb97042ee150'],
    '_review/ss1563-navigation-current/telegram-1024-current.png' => ['17c53497492197020fdd391da6621b12221f742d27d8d7c7f9f7bae8b8e2070e'],
    '_review/ss1563-navigation-current/telegram-1024-no-match.png' => ['0ad3c8cb07003aba0100be66f6a1933d755050ef0e0aa0a43d10a1e84cccfd93'],
    '_review/ss1563-navigation-current/telegram-1024-non-current.png' => ['053a658ecb7942ae1b7b015b866099c088cc73c89854af13a8b1120c481c968a'],
    '_review/ss1563-navigation-current/telegram-1440-child.png' => ['31ec5f3d0bf954b2ec2fd86e73a11a10f23b72378628c0f6aa22c37edc88d789'],
    '_review/ss1563-navigation-current/telegram-1440-current.png' => ['910a5c9ec45dc517514c4105cb046add3f06bcd5250481bbf13b5aee46694c09'],
    '_review/ss1563-navigation-current/telegram-1440-no-match.png' => ['257b7a3cde8aec7d15553b62a620044d8fbf0e7ee0b21e8628a825010f0b2fa7'],
    '_review/ss1563-navigation-current/telegram-1440-non-current.png' => ['3f9c01a6ce0c2bf1934ed6d0157fb9744b1ccd519a60a69c71614f3686e8883d'],
    '_review/ss1563-navigation-current/telegram-1920-child.png' => ['c74c7a6681beff81e8832c2610806e5da05752cc1ff94563ab03a35f826fc286'],
    '_review/ss1563-navigation-current/telegram-1920-current.png' => ['12ead1250dca6d25d5c7e0990e6aa1accab95b0b4edae5ee393b222319ba1aea'],
    '_review/ss1563-navigation-current/telegram-1920-no-match.png' => ['28fca9d8022fc63cd6d581201e4f4862a4265896e43c85573967615032441d13'],
    '_review/ss1563-navigation-current/telegram-1920-non-current.png' => ['4d4f7f25b548a009969fb6be68bcd9fc4aafe62ceb29842b6f0338d80e212246'],
    '_review/ss1563-navigation-current/tilez-1024-child.png' => ['5a43ddb0ef8903d9210fdefa84df11064f1c448c5218d754ebd48e95d3922c29'],
    '_review/ss1563-navigation-current/tilez-1024-current.png' => ['e7e2dc5def4381ab83dda7cf368522dcfd8e18f57b9fa761cf6ba36a66bbd48e'],
    '_review/ss1563-navigation-current/tilez-1024-no-match.png' => ['c6d0be26591b43d8bdf84112100c9822dae60bb977393ae24ccbcfa451a85cfb'],
    '_review/ss1563-navigation-current/tilez-1024-non-current.png' => ['76cbc75660cfbbc35f4cd21b9167aaf9ccaa8e76d89ebf323405db4b8366585f'],
    '_review/ss1563-navigation-current/tilez-1440-child.png' => ['8ca82e77af53c12290bfd3cc1c8e4593447dc336ba4a07c3279d3c60363803f9'],
    '_review/ss1563-navigation-current/tilez-1440-current.png' => ['9ab77d88020fc1155f65754e847c8e6d7ef506485aca9cc92f9f7f95aab69ddd'],
    '_review/ss1563-navigation-current/tilez-1440-no-match.png' => ['2296936c11080ae9f4b8914f954c07c2b11b8d2273995fa1f7423ff8f4cc2726'],
    '_review/ss1563-navigation-current/tilez-1440-non-current.png' => ['02ba9457c528dd03b84201686e2405294ff400a5b2ebe4eb5641d3885fb39a6e'],
    '_review/ss1563-navigation-current/tilez-1920-child.png' => ['1c07962919c3bbb0a48721a5dcb54e21a2b69cecd6880a969a33533cba5dc98b'],
    '_review/ss1563-navigation-current/tilez-1920-current.png' => ['8b115d6c52f85d545ad59782a5d88a316514441aa901847ccf5535d32f0427af'],
    '_review/ss1563-navigation-current/tilez-1920-no-match.png' => ['7f7491cb13a3020cb45ddcab348ec3c9d95b883024bacdb82a7ac78dcd2c3858'],
    '_review/ss1563-navigation-current/tilez-1920-non-current.png' => ['8f46fc74bc70dd2fbf3963966380475f8ab60c685ce0b0488d50521a55cfd494'],
];

/** Remove only unchanged release-owned copies. Never recurse through local content. */
function snapsmack_cleanup_shipped_review_files(string $root): array {
    $removed = [];
    $failed = [];
    if (!is_file($root . '/core/db.php') || is_file($root . '/smack-central/sc-config.php')) {
        return ['removed' => $removed, 'failed' => $failed];
    }

    $root = realpath($root);
    if ($root === false || !is_dir($root . '/_review') || is_link($root . '/_review')) {
        return ['removed' => $removed, 'failed' => $failed];
    }

    $directories = [];
    foreach (SNAPSMACK_SHIPPED_REVIEW_FILES as $path => $hashes) {
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        for ($parent = dirname($target); $parent !== $root; $parent = dirname($parent)) {
            $directories[$parent] = true;
        }
        if (!is_file($target) || is_link($target) || realpath($target) !== $target) continue;
        if (!in_array(hash_file('sha256', $target), $hashes, true)) continue;
        if (@unlink($target)) {
            $removed[] = $path;
        } else {
            $failed[] = $path;
        }
    }
    krsort($directories, SORT_STRING);
    foreach (array_keys($directories) as $directory) {
        if (is_dir($directory) && !is_link($directory) && realpath($directory) === $directory) {
            @rmdir($directory); // Empty only. Locally added or edited files stay in place.
        }
    }
    return ['removed' => $removed, 'failed' => $failed];
}

// ===== SNAPSMACK EOF =====
