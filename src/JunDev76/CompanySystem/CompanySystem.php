<?php

namespace JunDev76\CompanySystem;

use FormSystem\form\ButtonForm;
use FormSystem\form\CustomForm;
use FormSystem\form\ModalForm;
use JsonException;
use JunDev76\EconomySystem\EconomySystem;
use JunDev76\SteakMapImageSystem\SteakImagePlaceSession;
use JunKR\CrossUtils;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\TextFormat;

class CompanySystem extends PluginBase implements Listener{
    use SingletonTrait;

    protected function onLoad() : void{
        self::setInstance($this);
    }

    protected array $playerCompanyCache = [];

    protected array $company_db = [];
    protected array $removeOrRenamedCompany = [];
    protected array $system_db = [];

    /**
     * @throws JsonException
     */
    protected function onEnable() : void{
        //TODO: 칭호 쪽으로 과금 (색, 특수문자)
        $this->company_db = CrossUtils::getDataArray($this->getDataFolder() . 'companydata.json');
        $this->removeOrRenamedCompany = CrossUtils::getDataArray($this->getDataFolder() . 'removerenamecompany.json');
        $this->system_db = CrossUtils::getDataArray($this->getDataFolder() . 'systemdata.json');

        CrossUtils::registercommand('회사', $this, '회사 시스템');

        if(($this->system_db['date'] ?? null) !== date('ymd')){
            $this->loadChart();
        }
    }

    /**
     * @throws JsonException
     */
    protected function loadChart() : void{
        $this->system_db['date'] = date('ymd');

        $companys = $this->company_db;

        $cps = [];
        $index = 0;
        foreach($companys as $row){
            $cps[$index++] = $row['cp'];
        }
        array_multisort($cps, SORT_DESC, $companys);
        $companys = array_slice($companys, 0, 6);

        $get = function(string $name, int $day){
            return ($this->system_db['cplog'][date('ymd', time() - 86400 * $day)][$name] ?? 0);
        };

        $url = '';
        foreach($companys as $company){
            $this->system_db['cplog'][date('ymd')][$company['name']] = $company['cp'];
            $name = $company['name'];
            unset($this->system_db[date('ymd', time() - 86400 * 4)][$name]);
            $url .= "$name=" . json_encode([
                    $get($name, 3),
                    $get($name, 2),
                    $get($name, 1),
                    $company['cp']
                ], JSON_THROW_ON_ERROR) . '&';
        }

        $this->getServer()->getAsyncPool()->submitTask(new class($url) extends AsyncTask{
            public string $path;

            public function __construct(public string $url){
            }

            public function onRun() : void{
                $temp = tmpfile();
                fwrite($temp, file_get_contents('https://www.crsbe.kr/company_graph/?' . $this->url));
                fseek($temp, 0);
                $path = stream_get_meta_data($temp)['uri'];

                $json_data = [
                    "content" => '<t:' . time() . ":D> 기준,\n크로스팜 회사들의 순위입니다.",
                    "tts" => "false",
                    "file" => curl_file_create($path, 'image/jpeg', 'crossfarm_company_chart.jpg')
                ];

                $curl = curl_init('https://discord.com/api/webhooks/951465338531381258/aULM9jw0e3V6GfE-c35hz27gYzUuxilWNFc0mqlfNuuO1OBHtIo5hm5TNRnaLK2NjPAd');
                curl_setopt($curl, CURLOPT_TIMEOUT, 5);        // 5 seconds
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5); // 5 seconds
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data);

                curl_exec($curl);
                curl_close($curl);

                fclose($temp);

                $temp = tmpfile();
                fwrite($temp, file_get_contents('https://www.crsbe.kr/company_graph/mc_index/?' . $this->url));
                fseek($temp, 0);
                $path = stream_get_meta_data($temp)['uri'];
                rename($path, $path . '.jpg');
                $path .= '.jpg';
                $this->path = $path;
            }

            public function onCompletion() : void{
                //CustomBandAPI::writePost(date("Y년 m월 10일 H시 기준") . ",(line)크로스팜의 회사들의 순위입니다.(line)(line)#회사 #차트 #회사차트 #통계", 'https://www.crsbe.kr/company_graph/band.png');
                (new SteakImagePlaceSession($this->path, new Vector3(232, 67, 249), new Vector3(232, 65, 246), Server::getInstance()->getWorldManager()->getWorldByName('spawnworld')));
                unlink($this->path);
            }

        });
    }

    /**
     * @throws JsonException
     */
    protected function onDisable() : void{
        file_put_contents($this->getDataFolder() . 'companydata.json', json_encode($this->company_db, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        file_put_contents($this->getDataFolder() . 'removerenamecompany.json', json_encode($this->removeOrRenamedCompany, JSON_THROW_ON_ERROR));
        file_put_contents($this->getDataFolder() . 'systemdata.json', json_encode($this->system_db, JSON_THROW_ON_ERROR));
    }

    public function getCompanyDB(string $player) : ?array{
        $player = strtolower($player);

        if(isset($this->playerCompanyCache[$player])){
            return $this->playerCompanyCache[$player];
        }

        foreach($this->company_db as $company){
            if($company['owner'] === $player){
                $this->playerCompanyCache[$player] = $company;
                return $company;
            }
            if(in_array($player, $company['players'], true)){
                $this->playerCompanyCache[$player] = $company;
                return $company;
            }
        }
        $this->playerCompanyCache[$player] = null;
        return null;
    }

    protected function make_company(Player $player, $etc = '') : void{
        $make_company = new CustomForm(function(Player $player, $data){
            if(empty($data[0])){
                return;
            }
            if(EconomySystem::getInstance()->getMoney($player) < 3000000){
                $player->sendMessage('§a§l[회사] §r§7돈이 부족해요.');
                return;
            }

            $name = TextFormat::clean(trim($data[0]));
            if(empty($name)){
                return;
            }
            if(isset($this->removeOrRenamedCompany[$name]) || isset($this->company_db[$name])){
                $this->make_company($player, '§c이미 존재하거나 사용되었던 회사이름 입니다.' . PHP_EOL . '§f');
                return;
            }

            if(!preg_match("/^[ㄱ-ㅎ|가-힣|a-z|A-Z|0-9|]+$/", $name)){
                $this->make_company($player, '§c영어/한국어/숫자만 입력 가능해요..' . PHP_EOL . '§f');
                return;
            }

            $color = ['c', 'f', 'g', 1, 2, 3, 4, 5, 6, 7][($data[2]) ?? 1];

            EconomySystem::getInstance()->reduceMoney($player, 3000000);

            $this->company_db[$name] = [
                'name' => $name,
                'owner' => strtolower($player->getName()),
                'subowners' => [],
                'players' => [],
                'logo' => 'textures/blocks/' . $this->public_logos[random_int(0, count($this->public_logos) - 1)],
                'color' => $color,
                'hire' => true,
                'wantplayers' => [],
                'maxplayers' => 5,
                'created' => time(),
                'ended' => null,
                'announcement' => null,
                'cp' => 0,
                'cplog' => []
            ];

            $this->make_company_notice($player->getName(), $name, $color);
            $this->getServer()->broadcastMessage('§a§l[회사] §r§e' . $player->getName() . '님§f이 §e' . $name . '§r§f회사를 창립했습니다!');
        });
        $make_company->setTitle('§l회사 만들기');
        $make_company->addInput("회사 이름을 입력해주세요.\n§r§o§8(특수문자, 색코드는 제외됨)", '', '크로스컴퍼니');
        $make_company->addLabel($etc);
        //TODO: '§aA가1', '§bA가1', '§dA가1', '§eA가1' 은 구매로 ㄱㄱ
        $make_company->addDropdown('회사색', [
            '§cA가1',
            '§fA가1',
            '§gA가1',
            '§1A가1',
            '§2A가1',
            '§3A가1',
            '§4A가1',
            '§5A가1',
            '§6A가1',
            '§7A가1'
        ]);
        $make_company->addDropdown('최대 인원', ['5명 (기본)']);
        $make_company->addDropdown('로고', ['랜덤 (기본)']);
        $make_company->addDropdown('공고', ['없음 (기본)']);
        $make_company->addDropdown('채용중', ['채용중 (기본)']);
        $make_company->addLabel("\n\n§r§f회사를 만들때에는 창업비용 §e3,000,000원§f이 소모됩니다.");
        $make_company->sendForm($player);
    }

    protected function make_company_notice(string $owner, string $name, $color) : void{
        $owner = strtolower($owner);

        //TODO: BandReporter::getInstance()->addPost("#회사창립 #$name #회사\n\n회사 {$name}가 {$owner}에 의해 창립되었습니다!");
        $this->getServer()->getAsyncPool()->submitTask(new class($owner, $name, $color) extends AsyncTask{

            public function __construct(public string $owner, public string $name, public string $color){
            }

            public function onRun() : void{
                $owner = $this->owner;
                $name = $this->name;
                $color = $this->color;

                //=======================================================================================================
                // Create new webhook in your Discord channel settings and copy&paste URL
                //=======================================================================================================

                $webhookurl = 'https://discord.com/api/webhooks/951097118431543347/RR-Ps4VhnWft8ewuJKjww8P4w_UGsPmOMX873dZeDtRIo8KQMFxHaEVN9FLmgtLQ41xG';

                //=======================================================================================================
                // Compose message. You can use Markdown
                // Message Formatting -- https://discordapp.com/developers/docs/reference#message-formatting
                //========================================================================================================

                $timestamp = date("c");

                $json_data = json_encode([
                    // Message
                    "content" => "",

                    // Embeds Array
                    "embeds" => [
                        [
                            // Embed Title
                            "title" => "$name 회사 창립!",

                            // Embed Description
                            "description" => "회사 {$name}(이)가 {$owner}에 의해 창립되었습니다!",

                            // Timestamp of embed must be formatted as ISO8601
                            "timestamp" => $timestamp,

                            // Embed left border color in HEX
                            "color" => hexdec([
                                                  0 => 'FF5555',
                                                  1 => 'FFFFFF',
                                                  2 => 'EFCE16',
                                                  3 => '0000AE',
                                                  4 => '02AA00',
                                                  5 => '01A8AC',
                                                  6 => 'AA0000',
                                                  7 => 'AB01A0',
                                                  8 => 'FAAC05',
                                                  9 => 'AAAAAA'
                                              ][$this->color]),

                            // Footer
                            "footer" => [
                                "text" => "크로스팜 회사 시스템",
                                "icon_url" => "https://cdn.discordapp.com/avatars/951097118431543347/e0488e925265a20cb962d694dc2649c3.webp?size=480"
                            ],

                            // Thumbnail
                            //"thumbnail" => [
                            //    "url" => "https://ru.gravatar.com/userimage/28503754/1168e2bddca84fec2a63addb348c571d.jpg?size=400"
                            //],

                            // Author
                            "author" => [
                                "name" => $owner,
                            ],
                        ]
                    ]

                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $ch = curl_init($webhookurl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

                curl_exec($ch);
                // If you need to debug, or find out why you can't send message uncomment line below, and execute script.
                // echo $response;
                curl_close($ch);
            }
        });
    }

    public function companyInfoString(array $companyData) : string{
        $data = [
            '',
            '회사이름: §e' . $companyData['name'],
            '대 표 자: §e' . $companyData['owner']
        ];

        foreach($companyData['subowners'] as $owners){
            $data[] = '부 대 표: §e' . $owners;
        }

        $data[] = '';

        if($companyData['players'] !== null && count($companyData['players']) !== 0){
            $data[] = '사 원 수: §e' . count($companyData['players']) . '명';
        }
        foreach($companyData['players'] as $owners){
            $data[] = '사    원: §e' . $owners;
        }

        $data[] = '채 용 중: §e' . ($companyData['hire'] ? '§a§l채용중' : '§c§l채용중이 아님');
        $data[] = '최대사원: §e' . $companyData['maxplayers'] . '명';

        $data[] = '';
        $data[] = '개 설 일: §e' . date('Y년 m월 d일 H시 i분 s초', $companyData['created']);
        if($companyData['ended'] !== null){
            $data[] = '폐 업 일: §e' . date('Y년 m월 d일 H시 i분 s초', $companyData['ended']);
        }

        $data[] = '';
        $data[] = '공    고: §e' . ($companyData['announcement'] === null ? '0개' : count($companyData['announcement']) . '개');

        $data[] = '';
        $data[] = 'CP(자산): §e' . EconomySystem::getInstance()->koreanWonFormat($companyData['cp'], 'CP');

        $data[] = '';

        return implode("\n§r§f", $data);
    }

    public function companyInfoForm(Player $player, array $companyData) : void{
        $playerCompany = $this->getCompanyDB($player->getName());

        $form = new ButtonForm(function(Player $player, $data) use ($playerCompany, $companyData){
            if($data === 0){
                $this->companyInfoForm($player, $companyData);
                return;
            }
            if($data === 2){
                if(in_array(strtolower($player->getName()), $companyData['wantplayers'], true)){
                    $form = new ModalForm(function(Player $player, $data) use ($companyData) : void{
                        if($data){
                            unset($this->company_db[$companyData['name']]['wantplayers'][array_search(strtolower($player->getName()), $companyData['wantplayers'], true)]);
                            $this->playerCompanyCache = [];
                            $player->sendMessage('§a§l[회사] §r§7입사 신청을 철회했어요.');
                        }
                    });

                    $form->setTitle('§l입사신청');
                    $form->setContent('이미 가입신청 상태입니다.' . PHP_EOL . '취소할까요?');
                    $form->setButton1("§l§c입사신청 취소");
                    $form->setButton2('창 닫기');

                    $form->sendForm($player);
                    return;
                }

                if($playerCompany === null && $companyData['hire']){
                    if($companyData['maxplayers'] <= count($companyData['players'])){
                        $player->sendMessage('§a§l[회사] §r§7해당 회사에 자리가 없어요.');
                        return;
                    }

                    $form = new ModalForm(function(Player $player, $data) use ($companyData) : void{
                        if($data){
                            $this->company_db[$companyData['name']]['wantplayers'][] = strtolower($player->getName());
                            $this->playerCompanyCache = [];
                            $player->sendMessage('§a§l[회사] §r§e' . $companyData['name'] . '§7에 입사신청했어요.');
                        }
                    });

                    $form->setTitle('§l입사신청');
                    $form->setContent('해당 회사에 입사신청 할까요?');
                    $form->setButton1("§l§a입사신청");
                    $form->setButton2('창 닫기');

                    $form->sendForm($player);
                    return;
                }
            }
        });

        $form->setTitle('§l' . $companyData['name'] . '§r§l§8 || 회사정보');

        $form->setContent($this->companyInfoString($companyData));

        $form->addButton('§l' . $companyData['name'] . PHP_EOL . '§r§8해당 회사의 정보들 입니다.', false, ($companyData['logo']));

        $form->addButton('§l창닫기');

        if($playerCompany === null && $companyData['hire']){
            $form->addButton("§l회사 입사 신청");
        }

        $form->sendForm($player);
    }

    protected function search_company(Player $player, int $page = 0) : void{
        $companys = array_filter($this->company_db, static function($company){
            return $company['hire'];
        });

        $cps = [];
        $index = 0;
        foreach($companys as $row){
            $cps[$index++] = $row['cp'];
        }
        array_multisort($cps, SORT_DESC, $companys);

        $companys = array_chunk($companys, 10);
        if(!isset($companys[$page])){
            return;
        }

        $max = count($companys);
        $companys = $companys[$page];

        $hasLeft = $page === $max - 1 && $page !== 0;
        $hasRight = $page !== $max - 1 && $max !== 1;

        $form = new ButtonForm(function(Player $player, $data) use ($hasLeft, $hasRight, $page, $companys) : void{
            if($data === 0){
                return;
            }

            if($hasLeft && $data === 1){
                $this->search_company($player, $page - 1);
                return;
            }

            if(($hasRight && $data === 1) || ($hasLeft && $hasRight && $data === 2)){
                $this->search_company($player, $page + 1);
                return;
            }

            $index = $data;
            if($hasLeft && $hasRight){
                $index -= 3;
            }elseif($hasLeft || $hasRight){
                $index -= 2;
            }else{
                --$index;
            }

            if(!isset($companys[$index])){
                return;
            }

            $this->companyInfoForm($player, $companys[$index]);
        });

        $form->setTitle('§l§8< §6' . ($page + 1) . ' §8/ ' . $max . '>');
        $form->setContent('');

        $form->addButton("§l창닫기");
        if($hasLeft){
            $form->addButton('§l이전 페이지');
        }
        if($hasRight){
            $form->addButton('§l다음 페이지');
        }

        foreach($companys as $rank => $company){
            $rank++;
            $form->addButton("§l{$rank}등  " . $company['name'] . PHP_EOL . '§r§8CP: ' . EconomySystem::getInstance()->koreanWonFormat($company['cp'], 'CP') . ' CEO: ' . $company['owner']);
        }

        $form->sendForm($player);
    }

    protected array $public_logos = [
        "acacia_trapdoor.png",
        "amethyst_block.png",
        "amethyst_cluster.png",
        "ancient_debris_side.png",
        "ancient_debris_top.png",
        "anvil_base.png",
        "anvil_top_damaged_0.png",
        "anvil_top_damaged_1.png",
        "anvil_top_damaged_2.png",
        "azalea_leaves.png",
        "azalea_leaves_flowers.png",
        "azalea_leaves_flowers_opaque.png",
        "azalea_leaves_opaque.png",
        "azalea_plant.png",
        "azalea_side.png",
        "azalea_top.png",
        "bamboo_leaf.png",
        "bamboo_sapling.png",
        "bamboo_singleleaf.png",
        "bamboo_small_leaf.png",
        "bamboo_stem.png",
        "barrel_bottom.png",
        "barrel_side.png",
        "barrel_top.png",
        "barrel_top_open.png",
        "barrier.png",
        "basalt_side.png",
        "basalt_top.png",
        "beacon.png",
        "bedrock.png",
        "bee_nest_bottom.png",
        "bee_nest_front.png",
        "bee_nest_front_honey.png",
        "bee_nest_side.png",
        "bee_nest_top.png",
        "beehive_front.png",
        "beehive_front_honey.png",
        "beehive_side.png",
        "beehive_top.png",
        "beetroots_stage_0.png",
        "beetroots_stage_1.png",
        "beetroots_stage_2.png",
        "beetroots_stage_3.png",
        "bell_bottom.png",
        "bell_side.png",
        "bell_top.png",
        "big_dripleaf_side1.png",
        "big_dripleaf_side2.png",
        "big_dripleaf_stem.png",
        "big_dripleaf_top.png",
        "birch_trapdoor.png",
        "blackstone.png",
        "blackstone_top.png",
        "blast_furnace_front_off.png",
        "blast_furnace_front_on.png",
        "blast_furnace_side.png",
        "blast_furnace_top.png",
        "blue_ice.png",
        "bone_block_side.png",
        "bone_block_top.png",
        "bookshelf.png",
        "border.png",
        "brewing_stand.png",
        "brewing_stand_base.png",
        "brick.png",
        "budding_amethyst.png",
        "build_allow.png",
        "build_deny.png",
        "cake_bottom.png",
        "cake_inner.png",
        "cake_side.png",
        "cake_top.png",
        "calcite.png",
        "camera_back.png",
        "camera_front.png",
        "camera_side.png",
        "camera_top.png",
        "carrots_stage_0.png",
        "carrots_stage_1.png",
        "carrots_stage_2.png",
        "carrots_stage_3.png",
        "cartography_table_side1.png",
        "cartography_table_side2.png",
        "cartography_table_side3.png",
        "cartography_table_top.png",
        "cauldron_bottom.png",
        "cauldron_inner.png",
        "cauldron_side.png",
        "cauldron_top.png",
        "cave_vines_body.png",
        "cave_vines_body_berries.png",
        "cave_vines_head.png",
        "cave_vines_head_berries.png",
        "chest_front.png",
        "chest_side.png",
        "chest_top.png",
        "chiseled_nether_bricks.png",
        "chiseled_polished_blackstone.png",
        "chorus_flower.png",
        "chorus_flower_dead.png",
        "chorus_plant.png",
        "clay.png",
        "coal_block.png",
        "coal_ore.png",
        "coarse_dirt.png",
        "cobblestone.png",
        "cobblestone_mossy.png",
        "cocoa_stage_0.png",
        "cocoa_stage_1.png",
        "cocoa_stage_2.png",
        "comparator_off.png",
        "comparator_on.png",
        "compost.png",
        "compost_ready.png",
        "composter_bottom.png",
        "composter_side.png",
        "composter_top.png",
        "concrete_black.png",
        "concrete_blue.png",
        "concrete_brown.png",
        "concrete_cyan.png",
        "concrete_gray.png",
        "concrete_green.png",
        "concrete_light_blue.png",
        "concrete_lime.png",
        "concrete_magenta.png",
        "concrete_orange.png",
        "concrete_pink.png",
        "concrete_powder_black.png",
        "concrete_powder_blue.png",
        "concrete_powder_brown.png",
        "concrete_powder_cyan.png",
        "concrete_powder_gray.png",
        "concrete_powder_green.png",
        "concrete_powder_light_blue.png",
        "concrete_powder_lime.png",
        "concrete_powder_magenta.png",
        "concrete_powder_orange.png",
        "concrete_powder_pink.png",
        "concrete_powder_purple.png",
        "concrete_powder_red.png",
        "concrete_powder_silver.png",
        "concrete_powder_white.png",
        "concrete_powder_yellow.png",
        "concrete_purple.png",
        "concrete_red.png",
        "concrete_silver.png",
        "concrete_white.png",
        "concrete_yellow.png",
        "conduit_base.png",
        "conduit_cage.png",
        "conduit_closed.png",
        "conduit_open.png",
        "conduit_wind_horizontal.png",
        "conduit_wind_vertical.png",
        "copper_block.png",
        "copper_ore.png",
        "coral_blue.png",
        "coral_blue_dead.png",
        "coral_fan_blue.png",
        "coral_fan_blue_dead.png",
        "coral_fan_pink.png",
        "coral_fan_pink_dead.png",
        "coral_fan_purple.png",
        "coral_fan_purple_dead.png",
        "coral_fan_red.png",
        "coral_fan_red_dead.png",
        "coral_fan_yellow.png",
        "coral_fan_yellow_dead.png",
        "coral_pink.png",
        "coral_pink_dead.png",
        "coral_plant_blue.png",
        "coral_plant_blue_dead.png",
        "coral_plant_pink.png",
        "coral_plant_pink_dead.png",
        "coral_plant_purple.png",
        "coral_plant_purple_dead.png",
        "coral_plant_red.png",
        "coral_plant_red_dead.png",
        "coral_plant_yellow.png",
        "coral_plant_yellow_dead.png",
        "coral_purple.png",
        "coral_purple_dead.png",
        "coral_red.png",
        "coral_red_dead.png",
        "coral_yellow.png",
        "coral_yellow_dead.png",
        "cracked_nether_bricks.png",
        "cracked_polished_blackstone_bricks.png",
        "crafting_table_front.png",
        "crafting_table_side.png",
        "crafting_table_top.png",
        "crimson_fungus.png",
        "crimson_nylium_side.png",
        "crimson_nylium_top.png",
        "crimson_roots.png",
        "crimson_roots_pot.png",
        "crying_obsidian.png",
        "cut_copper.png",
        "dark_oak_trapdoor.png",
        "daylight_detector_inverted_top.png",
        "daylight_detector_side.png",
        "daylight_detector_top.png",
        "deadbush.png",
        "diamond_block.png",
        "diamond_ore.png",
        "dirt.png",
        "dirt_podzol_side.png",
        "dirt_podzol_top.png",
        "dirt_with_roots.png",
        "dispenser_front_horizontal.png",
        "dispenser_front_vertical.png",
        "double_plant_fern_carried.png",
        "double_plant_grass_carried.png",
        "double_plant_paeonia_bottom.png",
        "double_plant_paeonia_top.png",
        "double_plant_rose_bottom.png",
        "double_plant_rose_top.png",
        "double_plant_sunflower_bottom.png",
        "double_plant_sunflower_front.png",
        "double_plant_sunflower_top.png",
        "dragon_egg.png",
        "dried_kelp_side_a.png",
        "dried_kelp_side_b.png",
        "dried_kelp_top.png",
        "dripstone_block.png",
        "dropper_front_horizontal.png",
        "dropper_front_vertical.png",
        "emerald_block.png",
        "emerald_ore.png",
        "enchanting_table_bottom.png",
        "enchanting_table_side.png",
        "enchanting_table_top.png",
        "end_bricks.png",
        "end_gateway.png",
        "end_portal.png",
        "end_rod.png",
        "end_stone.png",
        "ender_chest_front.png",
        "ender_chest_side.png",
        "ender_chest_top.png",
        "endframe_eye.png",
        "endframe_side.png",
        "endframe_top.png",
        "exposed_copper.png",
        "exposed_cut_copper.png",
        "farmland_dry.png",
        "farmland_wet.png",
        "fletcher_table_side1.png",
        "fletcher_table_side2.png",
        "fletcher_table_top.png",
        "flower_allium.png",
        "flower_blue_orchid.png",
        "flower_cornflower.png",
        "flower_dandelion.png",
        "flower_houstonia.png",
        "flower_lily_of_the_valley.png",
        "flower_oxeye_daisy.png",
        "flower_paeonia.png",
        "flower_pot.png",
        "flower_rose.png",
        "flower_rose_blue.png",
        "flower_tulip_orange.png",
        "flower_tulip_pink.png",
        "flower_tulip_red.png",
        "flower_tulip_white.png",
        "flower_wither_rose.png",
        "flowering_azalea_side.png",
        "flowering_azalea_top.png",
        "frosted_ice_0.png",
        "frosted_ice_1.png",
        "frosted_ice_2.png",
        "frosted_ice_3.png",
        "furnace_front_off.png",
        "furnace_front_on.png",
        "furnace_side.png",
        "furnace_top.png",
        "gilded_blackstone.png",
        "glass.png",
        "glass_black.png",
        "glass_blue.png",
        "glass_brown.png",
        "glass_cyan.png",
        "glass_gray.png",
        "glass_green.png",
        "glass_light_blue.png",
        "glass_lime.png",
        "glass_magenta.png",
        "glass_orange.png",
        "glass_pane_top.png",
        "glass_pane_top_black.png",
        "glass_pane_top_blue.png",
        "glass_pane_top_brown.png",
        "glass_pane_top_cyan.png",
        "glass_pane_top_gray.png",
        "glass_pane_top_green.png",
        "glass_pane_top_light_blue.png",
        "glass_pane_top_lime.png",
        "glass_pane_top_magenta.png",
        "glass_pane_top_orange.png",
        "glass_pane_top_pink.png",
        "glass_pane_top_purple.png",
        "glass_pane_top_red.png",
        "glass_pane_top_silver.png",
        "glass_pane_top_white.png",
        "glass_pane_top_yellow.png",
        "glass_pink.png",
        "glass_purple.png",
        "glass_red.png",
        "glass_silver.png",
        "glass_white.png",
        "glass_yellow.png",
        "glazed_terracotta_black.png",
        "glazed_terracotta_blue.png",
        "glazed_terracotta_brown.png",
        "glazed_terracotta_cyan.png",
        "glazed_terracotta_gray.png",
        "glazed_terracotta_green.png",
        "glazed_terracotta_light_blue.png",
        "glazed_terracotta_lime.png",
        "glazed_terracotta_magenta.png",
        "glazed_terracotta_orange.png",
        "glazed_terracotta_pink.png",
        "glazed_terracotta_purple.png",
        "glazed_terracotta_red.png",
        "glazed_terracotta_silver.png",
        "glazed_terracotta_white.png",
        "glazed_terracotta_yellow.png",
        "glow_item_frame.png",
        "glow_lichen.png",
        "glowing_obsidian.png",
        "glowstone.png",
        "gold_block.png",
        "gold_ore.png",
        "grass_block_snow.png",
        "grass_carried.png",
        "grass_path_side.png",
        "grass_path_top.png",
        "grass_side_carried.png",
        "grass_side_snowed.png",
        "grass_top.png",
        "gravel.png",
        "hanging_roots.png",
        "hardened_clay.png",
        "hardened_clay_stained_black.png",
        "hardened_clay_stained_blue.png",
        "hardened_clay_stained_brown.png",
        "hardened_clay_stained_cyan.png",
        "hardened_clay_stained_gray.png",
        "hardened_clay_stained_green.png",
        "hardened_clay_stained_light_blue.png",
        "hardened_clay_stained_lime.png",
        "hardened_clay_stained_magenta.png",
        "hardened_clay_stained_orange.png",
        "hardened_clay_stained_pink.png",
        "hardened_clay_stained_purple.png",
        "hardened_clay_stained_red.png",
        "hardened_clay_stained_silver.png",
        "hardened_clay_stained_white.png",
        "hardened_clay_stained_yellow.png",
        "hay_block_side.png",
        "hay_block_top.png",
        "honey_bottom.png",
        "honey_side.png",
        "honey_top.png",
        "honeycomb.png",
        "hopper_inside.png",
        "hopper_outside.png",
        "hopper_top.png",
        "ice.png",
        "ice_packed.png",
        "iron_bars.png",
        "iron_block.png",
        "iron_ore.png",
        "iron_trapdoor.png",
        "jigsaw_front.png",
        "jigsaw_lock.png",
        "jigsaw_side.png",
        "jukebox_side.png",
        "jukebox_top.png",
        "jungle_trapdoor.png",
        "ladder.png",
        "lapis_block.png",
        "lapis_ore.png",
        "large_amethyst_bud.png",
        "leaves_acacia_opaque.png",
        "leaves_big_oak_opaque.png",
        "leaves_birch_opaque.png",
        "leaves_jungle_opaque.png",
        "leaves_oak_opaque.png",
        "leaves_spruce_opaque.png",
        "lectern_base.png",
        "lectern_front.png",
        "lectern_sides.png",
        "lectern_top.png",
        "lever.png",
        "lightning_rod.png",
        "lodestone_side.png",
        "lodestone_top.png",
        "log_acacia.png",
        "log_acacia_top.png",
        "log_big_oak.png",
        "log_big_oak_top.png",
        "log_birch.png",
        "log_birch_top.png",
        "log_jungle.png",
        "log_jungle_top.png",
        "log_oak.png",
        "log_oak_top.png",
        "log_spruce.png",
        "log_spruce_top.png",
        "loom_bottom.png",
        "loom_front.png",
        "loom_side.png",
        "loom_top.png",
        "medium_amethyst_bud.png",
        "melon_side.png",
        "melon_stem_connected.png",
        "melon_stem_disconnected.png",
        "melon_top.png",
        "missing_tile.png",
        "mob_spawner.png",
        "moss_block.png",
        "mushroom_block_inside.png",
        "mushroom_block_skin_brown.png",
        "mushroom_block_skin_red.png",
        "mushroom_block_skin_stem.png",
        "mushroom_brown.png",
        "mushroom_red.png",
        "mycelium_side.png",
        "mycelium_top.png",
        "nether_brick.png",
        "nether_gold_ore.png",
        "nether_sprouts.png",
        "nether_wart_block.png",
        "nether_wart_stage_0.png",
        "nether_wart_stage_1.png",
        "nether_wart_stage_2.png",
        "netherite_block.png",
        "netherrack.png",
        "noteblock.png",
        "observer_front.png",
        "observer_side.png",
        "observer_top.png",
        "obsidian.png",
        "oxidized_copper.png",
        "oxidized_cut_copper.png",
        "piston_bottom.png",
        "piston_inner.png",
        "piston_side.png",
        "piston_top_normal.png",
        "piston_top_sticky.png",
        "planks_acacia.png",
        "planks_big_oak.png",
        "planks_birch.png",
        "planks_jungle.png",
        "planks_oak.png",
        "planks_spruce.png",
        "pointed_dripstone_down_base.png",
        "pointed_dripstone_down_frustum.png",
        "pointed_dripstone_down_merge.png",
        "pointed_dripstone_down_middle.png",
        "pointed_dripstone_down_tip.png",
        "pointed_dripstone_up_base.png",
        "pointed_dripstone_up_frustum.png",
        "pointed_dripstone_up_merge.png",
        "pointed_dripstone_up_middle.png",
        "pointed_dripstone_up_tip.png",
        "polished_basalt_side.png",
        "polished_basalt_top.png",
        "polished_blackstone.png",
        "polished_blackstone_bricks.png",
        "potatoes_stage_0.png",
        "potatoes_stage_1.png",
        "potatoes_stage_2.png",
        "potatoes_stage_3.png",
        "powder_snow.png",
        "prismarine_bricks.png",
        "prismarine_dark.png",
        "pumpkin_face_off.png",
        "pumpkin_face_on.png",
        "pumpkin_side.png",
        "pumpkin_stem_connected.png",
        "pumpkin_stem_disconnected.png",
        "pumpkin_top.png",
        "purpur_block.png",
        "purpur_pillar.png",
        "purpur_pillar_top.png",
        "quartz_block_bottom.png",
        "quartz_block_chiseled.png",
        "quartz_block_chiseled_top.png",
        "quartz_block_lines.png",
        "quartz_block_lines_top.png",
        "quartz_block_side.png",
        "quartz_block_top.png",
        "quartz_bricks.png",
        "quartz_ore.png",
        "rail_activator.png",
        "rail_activator_powered.png",
        "rail_detector.png",
        "rail_detector_powered.png",
        "rail_golden.png",
        "rail_golden_powered.png",
        "rail_normal.png",
        "rail_normal_turned.png",
        "raw_copper_block.png",
        "raw_gold_block.png",
        "raw_iron_block.png",
        "reactor_core_stage_0.png",
        "reactor_core_stage_1.png",
        "reactor_core_stage_2.png",
        "red_nether_brick.png",
        "red_sand.png",
        "red_sandstone_bottom.png",
        "red_sandstone_carved.png",
        "red_sandstone_normal.png",
        "red_sandstone_smooth.png",
        "red_sandstone_top.png",
        "redstone_block.png",
        "redstone_dust_cross.png",
        "redstone_dust_line.png",
        "redstone_lamp_off.png",
        "redstone_lamp_on.png",
        "redstone_ore.png",
        "redstone_torch_off.png",
        "redstone_torch_on.png",
        "repeater_off.png",
        "repeater_on.png",
        "respawn_anchor_bottom.png",
        "respawn_anchor_side0.png",
        "respawn_anchor_side1.png",
        "respawn_anchor_side2.png",
        "respawn_anchor_side3.png",
        "respawn_anchor_side4.png",
        "sand.png",
        "sandstone_bottom.png",
        "sandstone_carved.png",
        "sandstone_normal.png",
        "sandstone_smooth.png",
        "sandstone_top.png",
        "sapling_acacia.png",
        "sapling_birch.png",
        "sapling_jungle.png",
        "sapling_oak.png",
        "sapling_roofed_oak.png",
        "sapling_spruce.png",
        "sea_pickle.png",
        "shroomlight.png",
        "shulker_top_black.png",
        "shulker_top_blue.png",
        "shulker_top_brown.png",
        "shulker_top_cyan.png",
        "shulker_top_gray.png",
        "shulker_top_green.png",
        "shulker_top_light_blue.png",
        "shulker_top_lime.png",
        "shulker_top_magenta.png",
        "shulker_top_orange.png",
        "shulker_top_pink.png",
        "shulker_top_purple.png",
        "shulker_top_red.png",
        "shulker_top_silver.png",
        "shulker_top_undyed.png",
        "shulker_top_white.png",
        "shulker_top_yellow.png",
        "slime.png",
        "small_amethyst_bud.png",
        "small_dripleaf_side.png",
        "small_dripleaf_stem_bottom.png",
        "small_dripleaf_stem_top.png",
        "small_dripleaf_top.png",
        "smithing_table_bottom.png",
        "smithing_table_front.png",
        "smithing_table_side.png",
        "smithing_table_top.png",
        "smoker_bottom.png",
        "smoker_side.png",
        "smoker_top.png",
        "smooth_basalt.png",
        "snow.png",
        "soul_sand.png",
        "soul_soil.png",
        "soul_torch.png",
        "sponge.png",
        "sponge_wet.png",
        "spore_blossom.png",
        "spore_blossom_base.png",
        "spruce_trapdoor.png",
        "stone.png",
        "stone_andesite.png",
        "stone_andesite_smooth.png",
        "stone_diorite.png",
        "stone_diorite_smooth.png",
        "stone_granite.png",
        "stone_granite_smooth.png",
        "stone_slab_side.png",
        "stone_slab_top.png",
        "stonebrick.png",
        "stonebrick_carved.png",
        "stonebrick_cracked.png",
        "stonebrick_mossy.png",
        "stonecutter2_bottom.png",
        "stonecutter2_side.png",
        "stonecutter2_top.png",
        "stonecutter_bottom.png",
        "stonecutter_other_side.png",
        "stonecutter_side.png",
        "stonecutter_top.png",
        "structure_air.png",
        "structure_block.png",
        "structure_block_corner.png",
        "structure_block_data.png",
        "structure_block_export.png",
        "structure_block_load.png",
        "structure_block_save.png",
        "structure_void.png",
        "sweet_berry_bush_stage0.png",
        "sweet_berry_bush_stage1.png",
        "sweet_berry_bush_stage2.png",
        "sweet_berry_bush_stage3.png",
        "tallgrass.png",
        "target_side.png",
        "target_top.png",
        "tinted_glass.png",
        "tnt_bottom.png",
        "tnt_side.png",
        "tnt_top.png",
        "torch_on.png",
        "trapdoor.png",
        "trapped_chest_front.png",
        "trip_wire.png",
        "trip_wire_source.png",
        "tuff.png",
        "turtle_egg_not_cracked.png",
        "turtle_egg_slightly_cracked.png",
        "turtle_egg_very_cracked.png",
        "twisting_vines_base.png",
        "twisting_vines_bottom.png",
        "vine.png",
        "vine_carried.png",
        "warped_fungus.png",
        "warped_nylium_side.png",
        "warped_nylium_top.png",
        "warped_roots.png",
        "warped_roots_pot.png",
        "warped_wart_block.png",
        "weathered_copper.png",
        "weathered_cut_copper.png",
        "web.png",
        "weeping_vines_base.png",
        "weeping_vines_bottom.png",
        "wheat_stage_0.png",
        "wheat_stage_1.png",
        "wheat_stage_2.png",
        "wheat_stage_3.png",
        "wheat_stage_4.png",
        "wheat_stage_5.png",
        "wheat_stage_6.png",
        "wheat_stage_7.png",
        "wool_colored_black.png",
        "wool_colored_blue.png",
        "wool_colored_brown.png",
        "wool_colored_cyan.png",
        "wool_colored_gray.png",
        "wool_colored_green.png",
        "wool_colored_light_blue.png",
        "wool_colored_lime.png",
        "wool_colored_magenta.png",
        "wool_colored_orange.png",
        "wool_colored_pink.png",
        "wool_colored_purple.png",
        "wool_colored_red.png",
        "wool_colored_silver.png",
        "wool_colored_white.png",
        "wool_colored_yellow.png"
    ];

    public function logoUI(Player $player, $page = 0) : void{
        $db = $this->getCompanyDB($player->getName());
        if($db === null){
            return;
        }

        if($db['cp'] < 3000000){
            $player->sendMessage('§l§a[회사] §r§7CP가 부족해요.');
            return;
        }

        $arr = array_chunk($this->public_logos, 10);
        $hasNext = isset($arr[$page + 1]);
        $hasLeft = isset($arr[$page - 1]);

        $arr = $arr[$page];

        $form = new ButtonForm(function(Player $player, $data) use ($hasLeft, $hasNext, $page, $arr, $db){
            if($data === 0){
                return;
            }
            if($hasLeft && $data === 1){
                $this->logoUI($player, $page - 1);
                return;
            }
            if($hasNext && $data === 1){
                $this->logoUI($player, $page + 1);
                return;
            }
            if($hasLeft && $hasNext){
                if($data === 1){
                    $this->logoUI($player, $page - 1);
                    return;
                }
                if($data === 2){
                    $this->logoUI($player, $page + 1);
                    return;
                }
            }

            $index = --$data;
            if($hasLeft && $hasNext){
                $index -= 2;
            }elseif($hasLeft || $hasNext){
                $index--;
            }

            if(!isset($arr[$index])){
                return;
            }

            $this->company_db[$db['name']]['logo'] = 'textures/blocks/' . $arr[$index];
            $this->company_db[$db['name']]['cp'] -= 3000000;
            $player->sendMessage('§a§l[회사] §r§7로고를 지정했어요.');
            $this->playerCompanyCache = [];
        });
        $form->setTitle('§l로고 선택');

        $form->setContent('');
        $form->addButton('§l창닫기');
        if($hasLeft){
            $form->addButton('§l이전 페이지');
        }
        if($hasNext){
            $form->addButton('§l다음 페이지');
        }

        foreach($arr as $value){
            $form->addButton('해당 이미지를 로고로 선택', false, 'textures/blocks/' . $value);
        }

        $form->sendForm($player);
    }

    /**
     * @throws JsonException
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if($sender instanceof Player && $command->getName() === '회사'){
            if(($args[0] ?? null) === 'chart' && $this->getServer()->isOp($sender->getName())){
                $this->loadChart();
                return true;
            }
            $db = $this->getCompanyDB($sender->getName());
            if($db === null){
                $form = new ButtonForm(function(Player $player, $data){
                    if($data === 0){
                        $this->make_company($player);
                        return;
                    }
                    if($data === 1){
                        $this->search_company($player);
                        return;
                    }
                    if($data === 2){
                        //TODO: 채용공고
                        return;
                    }
                });
                $form->setTitle('§l회사');
                $form->setContent('');

                $form->addButton("§l회사 만들기\n§r§8회사를 창립합니다.");
                $form->addButton("§l회사 찾기\n§r§8회사를 찾습니다.");
                //$form->addButton("§l채용공고 찾기");

                $form->sendForm($sender);
                return true;
            }

            $isOwner = $db['owner'] === strtolower($sender->getName());
            $isSubowners = in_array(strtolower($sender->getName()), $db['subowners'], true);

            //TODO:

            $form = new ButtonForm(function(Player $player, $data, $tag) use ($db){
                if($data === 0){
                    $this->getServer()->dispatchCommand($player, '회사');
                    return;
                }
                if($data === 1){
                    return;
                }

                if($tag === 'cpin'){
                    $form = new CustomForm(function(Player $player, $data) use ($db){
                        if(!is_numeric($data[0])){
                            $player->sendMessage('§a§l[회사] §r§7숫자만 입력해주세요.');
                            return;
                        }
                        if($data[0] <= 0){
                            $player->sendMessage('§a§l[회사] §r§71원 이상 넣어주세요.');
                            return;
                        }
                        if(floor($data[0]) - $data[0] !== (float) 0){
                            $player->sendMessage('§a§l[회사] §r§7정수만 입력해주세요.');
                            return;
                        }

                        if(EconomySystem::getInstance()->getMoney($player) < $data[0]){
                            $player->sendMessage('§a§l[회사] §r§7개인자금(돈)이 부족해요.');
                            return;
                        }

                        EconomySystem::getInstance()->reduceMoney($player, $data[0]);
                        $this->company_db[$db['name']]['cplog'][] = ['cpin', [$player->getName(), $data[0]]];
                        $this->company_db[$db['name']]['cp'] += $data[0];
                        $this->playerCompanyCache = [];
                        $player->sendMessage('§a§l[회사] §r§e' . EconomySystem::getInstance()->koreanWonFormat($data[0], 'CP') . '§r§7를 넣었어요.');
                        return;
                    });
                    $form->setTitle('§lCP 넣기');
                    $form->addInput('입금을 원하시는 CP를 입력해주세요.', '', '100000');
                    $form->sendForm($player);
                    return;
                }
                if($tag === 'cpout'){
                    $form = new CustomForm(function(Player $player, $data) use ($db){
                        if(!is_numeric($data[0])){
                            $player->sendMessage('§a§l[회사] §r§7숫자만 입력해주세요.');
                            return;
                        }
                        if($data[0] <= 0){
                            $player->sendMessage('§a§l[회사] §r§71원 이상 입력해주세요.');
                            return;
                        }

                        if($this->company_db[$db['name']]['cp'] < $data[0]){
                            $player->sendMessage('§a§l[회사] §r§7CP가 부족해요.');
                            return;
                        }

                        $data[0] -= ($data[0] * 0.05);
                        EconomySystem::getInstance()->riseMoney($player, $data[0]);
                        $this->company_db[$db['name']]['cplog'][] = ['cpout', [$player->getName(), $data[0]]];
                        $this->company_db[$db['name']]['cp'] -= $data[0];
                        $player->sendMessage('§a§l[회사] §r§e' . EconomySystem::getInstance()->koreanWonFormat($data[0], 'CP') . '§r§7를 인출했어요.');
                        $this->playerCompanyCache = [];
                        return;
                    });
                    $form->setTitle('§lCP 인출');
                    $form->addInput('인출을 원하시는 CP를 입력해주세요.', '', '100000');
                    $form->sendForm($player);
                    return;
                }
                if($tag === 'logo'){
                    $this->logoUI($player);
                    return;
                }

                if($tag === 'name'){
                    $form = new CustomForm(function(Player $player, $data) use ($db){
                        if($db['cp'] < 3000000){
                            $player->sendMessage('§l§a[회사] §r§7CP가 부족해요.');
                            return;
                        }

                        if(empty($data[0])){
                            return;
                        }
                        $name = TextFormat::clean(trim($data[0]));
                        if(empty($name)){
                            return;
                        }

                        if($name === $db['name']){
                            $player->sendMessage('§a§l[회사] §r§c같은 이름으로 변경할 수 없어요');
                            return;
                        }

                        if(isset($this->removeOrRenamedCompany[$name]) || isset($this->company_db[$name])){
                            $player->sendMessage('§a§l[회사] §r§c이미 존재하거나 사용되었던 회사이름 입니다.');
                            return;
                        }

                        if(!preg_match("/^[ㄱ-ㅎ|가-힣|a-z|A-Z|0-9|]+$/", $name)){
                            $player->sendMessage('§a§l[회사] §r§c영어/한국어/숫자만 입력 가능해요.');
                            return;
                        }

                        $d = $this->company_db[$db['name']];
                        unset($this->company_db[$db['name']]);
                        $d['cp'] -= 3000000;
                        $d['name'] = $name;
                        $this->company_db[$name] = $d;
                        if(!isset($this->removeOrRenamedCompany[$db['name']])){
                            $this->removeOrRenamedCompany[$db['name']] = [];
                        }
                        $this->removeOrRenamedCompany[$db['name']][] = 'renamed to ' . $name . ' by ' . $player->getName() . ' at ' . date('y/m/d H:i:s');
                        $this->playerCompanyCache = [];
                        $player->sendMessage('§a§l[회사] §r§f회사 이름을 변경했어요.');
                    });
                    $form->setTitle('§l회사 이름 변경');
                    $form->addInput('회사 이름을 입력해주세요.');
                    $form->sendForm($player);
                    return;
                }

                if($tag === 'join'){
                    $p = [];
                    foreach($db['wantplayers'] as $wantplayer){
                        if($this->getCompanyDB($wantplayer) !== null){
                            continue;
                        }
                        $p[] = $wantplayer;
                    }
                    $form = new ButtonForm(function(Player $player, $data) use ($p, $db) : void{
                        if($data === 0){
                            return;
                        }
                        $data--;

                        $p = array_values($p);

                        if(!isset($p[$data])){
                            return;
                        }

                        if($db['maxplayers'] <= count($db['players'])){
                            $player->sendMessage('§a§l[회사] §r§f회사의 최대인원을 넘어요.');
                            return;
                        }

                        $db = $this->company_db[$db['name']];
                        unset($db['wantplayers'][$data]);
                        $db['players'][] = strtolower($p[$data]);
                        $this->company_db[$db['name']] = $db;
                        $this->playerCompanyCache = [];
                        $player->sendMessage('§a§l[회사] §r§f해당 유저의 가입신청을 받았어요.');
                    });
                    $form->setTitle('§l가입신청');
                    $form->setContent('');
                    $form->addButton('§l창닫기');
                    foreach($p as $wantplayer){
                        $form->addButton('§l' . $wantplayer . "\n§r§7해당 유저를 사원으로 받습니다.");
                    }
                    $form->sendForm($player);
                    return;
                }
                if($tag === 'cut'){
                    $players = $db['players'];

                    $form = new ButtonForm(function(Player $player, $data) use ($players, $db){
                        if($data === 0){
                            return;
                        }
                        $data--;
                        $players = array_values($players);
                        if(!isset($players[$data])){
                            return;
                        }

                        unset($players[$data]);
                        $this->company_db[$db['name']]['players'] = $players;
                        $this->playerCompanyCache = [];
                        $player->sendMessage('§a§l[회사] §r§7해당 유저를 잘랐어요.');
                    });

                    $form->setTitle('§l해고');
                    $form->setContent('');
                    $form->addButton('§l창닫기');
                    foreach($players as $p){
                        $form->addButton('§l' . $p . "\n§r§8해당 유저를 자릅니다.");
                    }

                    $form->sendForm($player);
                    return;
                }
                if($tag === 'leave'){
                    $form = new ModalForm(function(Player $player, $data) use ($db){
                        if($data === true){
                            unset($db['players'][array_search(strtolower($player->getName()), $db['players'], true)], $db['subowners'][array_search(strtolower($player->getName()), $db['subowners'], true)]);
                            $this->company_db[$db['name']] = $db;

                            $this->playerCompanyCache = [];
                            $player->sendMessage('§a§l[회사] §r§e' . $db['name'] . '§r§f에서 퇴사했어요.');
                        }
                    });
                    $form->setTitle('§l퇴사');
                    $form->setContent("정말 퇴사할까요?\n다시 입사하려면 대표의 승인이 필요하며,\n직책이 있다면 직책을 잃습니다.");
                    $form->setButton1('§l§c퇴사');
                    $form->setButton2('취소');
                    $form->sendForm($player);
                    return;
                }
                if($tag === 'delete'){
                    $form = new ModalForm(function(Player $player, $data) use ($db){
                        if($data === true){
                            unset($this->company_db[$db['name']]);
                            if(!isset($this->removeOrRenamedCompany[$db['name']])){
                                $this->removeOrRenamedCompany[$db['name']] = [];
                            }
                            $this->removeOrRenamedCompany[$db['name']][] = 'deleted by ' . $player->getName() . ' at ' . date('y/m/d H:i:s');
                            $this->playerCompanyCache = [];
                            $player->sendMessage('§a§l[회사] §r§e' . $db['name'] . '§r§f(을)를 폐업했어요.');

                            //TODO: BandReporter::getInstance()->addPost("#회사폐업 #{$db['name']} #회사\n\n회사 {$db['name']}가 {$player->getName()}에 의해 폐업되었습니다.");
                            $this->getServer()->getAsyncPool()->submitTask(new class($player->getName(), $db['name']) extends AsyncTask{

                                public function __construct(public string $owner, public string $name){
                                }

                                public function onRun() : void{
                                    $owner = $this->owner;
                                    $name = $this->name;

                                    //=======================================================================================================
                                    // Create new webhook in your Discord channel settings and copy&paste URL
                                    //=======================================================================================================

                                    $webhookurl = 'https://discord.com/api/webhooks/951097118431543347/RR-Ps4VhnWft8ewuJKjww8P4w_UGsPmOMX873dZeDtRIo8KQMFxHaEVN9FLmgtLQ41xG';

                                    //=======================================================================================================
                                    // Compose message. You can use Markdown
                                    // Message Formatting -- https://discordapp.com/developers/docs/reference#message-formatting
                                    //========================================================================================================

                                    $timestamp = date("c");

                                    $json_data = json_encode([
                                        // Message
                                        "content" => "",

                                        // Embeds Array
                                        "embeds" => [
                                            [
                                                // Embed Title
                                                "title" => "$name 회사 폐업",

                                                // Embed Description
                                                "description" => "회사 {$name}(이)가 {$owner}에 의해 폐업했습니다.",

                                                // Timestamp of embed must be formatted as ISO8601
                                                "timestamp" => $timestamp,

                                                // Embed left border color in HEX
                                                "color" => hexdec('ED4245'),

                                                // Footer
                                                "footer" => [
                                                    "text" => "크로스팜 회사 시스템",
                                                    "icon_url" => "https://cdn.discordapp.com/avatars/951097118431543347/e0488e925265a20cb962d694dc2649c3.webp?size=480"
                                                ],

                                                // Thumbnail
                                                //"thumbnail" => [
                                                //    "url" => "https://ru.gravatar.com/userimage/28503754/1168e2bddca84fec2a63addb348c571d.jpg?size=400"
                                                //],

                                                // Author
                                                "author" => [
                                                    "name" => $owner,
                                                ],
                                            ]
                                        ]

                                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                                    $ch = curl_init($webhookurl);
                                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
                                    curl_setopt($ch, CURLOPT_POST, 1);
                                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                                    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
                                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                                    curl_setopt($ch, CURLOPT_HEADER, 0);
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

                                    curl_exec($ch);
                                    // If you need to debug, or find out why you can't send message uncomment line below, and execute script.
                                    // echo $response;
                                    curl_close($ch);
                                }

                            });
                        }
                    });
                    $form->setTitle('§l폐업');
                    $form->setContent("정말 폐업할까요?\n모든 직원들이 회사를 잃습니다.");
                    $form->setButton1('§l§c폐업');
                    $form->setButton2('취소');
                    $form->sendForm($player);
                    return;
                }
                if($tag === 'givecompany'){
                    $players = $db['players'];

                    $form = new ButtonForm(function(Player $player, $data) use ($players, $db){
                        if($data === 0){
                            return;
                        }
                        $data--;
                        if(!isset($players[$data])){
                            return;
                        }

                        unset($players[$data]);
                        $this->company_db[$db['name']]['players'] = $players;
                        $this->playerCompanyCache = [];
                        $player->sendMessage('§a§l[회사] §r§7해당유저에게 양도했어요.');
                    });

                    $form->setTitle('§l회사 양도');
                    $form->setContent('');
                    $form->addButton('§l창닫기');
                    foreach($players as $p){
                        $form->addButton('§l' . $p . "\n§r§8해당 사원에게 양도합니다.");
                    }

                    $form->sendForm($player);
                    return;
                }
                if($tag === 'setsubowners'){
                    $players = $db['players'];

                    $form = new ButtonForm(function(Player $player, $data) use ($players, $db){
                        if($data === 0){
                            return;
                        }
                        $data--;
                        if(!isset($players[$data])){
                            return;
                        }
                        if(in_array($players[$data], $db['subowners'], true)){
                            $player->sendMessage('§a§l[회사] §r§7이미 부대표입니다.');
                            return;
                        }

                        $this->company_db[$db['name']]['subowners'][] = $players[$data];
                        $this->playerCompanyCache = [];
                        $player->sendMessage('§a§l[회사] §r§7해당유저를 부대표로 임명했어요.');
                    });

                    $form->setTitle('§l부대표 임명');
                    $form->setContent('');
                    $form->addButton('§l창닫기');
                    foreach($players as $p){
                        $form->addButton('§l' . $p . "\n§r§8해당 유저를 부대표로 임명합니다..");
                    }

                    $form->sendForm($player);
                    return;
                }
                if($tag === 'removesubowners'){
                    $players = $db['players'];

                    $form = new ButtonForm(function(Player $player, $data) use ($players, $db){
                        if($data === 0){
                            return;
                        }
                        $data--;
                        if(!isset($players[$data])){
                            return;
                        }
                        if(!in_array($players[$data], $db['subowners'], true)){
                            $player->sendMessage('§a§l[회사] §r§7이미 부대표가 아닙니다.');
                            return;
                        }

                        unset($this->company_db[$db['name']]['subowners'][array_search($players[$data], $db['subowners'], true)]);
                        $this->playerCompanyCache = [];
                        $player->sendMessage('§a§l[회사] §r§7해당유저를 부대표에서 박탈시켰어요.');
                    });

                    $form->setTitle('');
                    $form->setContent('');
                    $form->addButton('§l창닫기');
                    foreach($players as $p){
                        $form->addButton('§l' . $p . "\n§r§8해당 유저를 부대표로 임명합니다..");
                    }

                    $form->sendForm($player);
                    return;
                }
            });
            $form->setTitle('§l회사');
            $sub = $isSubowners ? '§a§l부대표' : '§r§e사원';
            $form->setContent($this->companyInfoString($db) . "\n\n§a내 직 책: §r§e" . ($isOwner ? '§a§lCEO' : $sub));

            $form->addButton('§l' . $db['name'] . PHP_EOL . '§r§8해당 회사의 정보들 입니다.', false, ($db['logo'] ?? ''));
            $form->addButton('§l창닫기');

            $form->addButton("§lCP 넣기\n§r§8", false, null, 'cpin');
            if($isOwner || $isSubowners){
                $form->addButton("§lCP 인출\n§r§8(수수료 0.5%%)", false, null, 'cpout');
                $form->addButton("§l가입 신청 받기\n§r§8가입신청을 받습니다.", false, null, 'join');
                $form->addButton("§l사원 자르기\n§r§8", false, null, 'cut');
                $form->addButton("§l회사 이름 변경\n§r§8(3,000,000CP 필요)", false, null, 'name');
                $form->addButton("§l회사 로고 변경\n§r§8(3,000,000CP 필요)", false, null, 'logo');
            }
            if($isOwner){
                $form->addButton("§l회사 양도\n§r§8(양도세 10,000,000CP 필요)", false, null, 'givecompany');
                $form->addButton("§l부대표 임명\n§r", false, null, 'setsubowners');
                $form->addButton("§l부대표 권한박탈\n§r", false, null, 'removesubowners');
                $form->addButton("§c§l폐업", false, 'textures/ui/crossout.png', 'delete');
            }else{
                $form->addButton("§c§l퇴사", false, 'textures/ui/crossout.png', 'leave');
            }

            $form->sendForm($sender);
            return true;
        }
        return true;
    }

}