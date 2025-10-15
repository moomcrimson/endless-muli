<?php
session_start();

// 1. 設定時區為台灣時間 (Asia/Taipei)，確保時間判斷的準確性。
date_default_timezone_set('Asia/Taipei');

// 2. 取得目前的小時 (24小時制，例如下午2點為 14)。
$current_hour = (int)date('H');

// 3. 在此處定義您不同時段要使用的 API 金鑰。
// AIzaSyDw_QezGIx_igRRBmv6u9ZRZF-dJXnesEs
$key_day_shift = 'AIzaSyARMBdbhK0BLat-DfbH6XftOOA3U90af7Q';     // 白天時段金鑰 (例如：早上 8 點到下午 6 點)
$key_night_shift = 'AIzaSyDw_QezGIx_igRRBmv6u9ZRZF-dJXnesEs';   // 夜間時段金鑰 (例如：下午 6 點到隔天早上 8 點)
$key_backup = 'AIzaSyCpzeqkzsPLJUBYEupuXxoGGxMFAKScvLU';      // 備用金鑰，如果以上都不符合時使用

// 4. 根據目前小時，選擇要使用的金鑰。
//    您可以自由修改時間區間。
if ($current_hour >= 8 && $current_hour < 18) {
    // 早上 8:00 到下午 5:59，使用白天金鑰
    define('GEMINI_API_KEY', $key_day_shift);
} else {
    // 其他所有時間 (下午 6:00 到隔天早上 7:59)，使用夜間金鑰
    define('GEMINI_API_KEY', $key_night_shift);
}

// 5. (可選) 如果您想在金鑰設定失敗時有一個備用方案，可以取消下面這段的註解
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', $key_backup);
}

define('GEMINI_MODEL_NAME', 'gemini-2.0-flash');

require_once __DIR__ . '/governance_dictionaries.php';
require_once __DIR__ . '/social_dictionaries.php';
require_once __DIR__ . '/biodiversity_dictionaries.php';
require_once __DIR__ . '/tnfd_dictionaries.php';
require_once __DIR__ . '/database_setup.php';

// 檢查使用者是否已登入，若未登入則導向到登入頁面
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
// PHP 錯誤報告設定
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/dpp_logic.php';

// --- 全局設定 ---
define('DB_FILE', __DIR__ . '/eco_data.sqlite');

const EQUIVALENTS = [
    'CAR_KM_PER_KG_CO2E' => 1 / 0.14, 'TREE_YEAR_PER_KG_CO2E' => 1 / 22, 'FLIGHT_KM_PER_KG_CO2E' => 1 / 0.2, 'BEEF_KG_PER_KG_CO2E' => 1 / 27,
    'KWH_PER_MJ' => 1 / 3.6, 'PHONE_CHARGES_PER_KWH' => 1 / 0.015, 'FRIDGE_DAYS_PER_KWH' => 1 / 1.5, 'LED_BULB_HOURS_PER_KWH' => 1 / 0.01, 'AC_HOURS_PER_KWH' => 1 / 1,
    'SHOWERS_PER_LITER' => 1 / 70, 'TOILET_FLUSHES_PER_LITER' => 1 / 8, 'A4_SHEETS_PER_LITER' => 1 / 10, 'WASHING_LOADS_PER_LITER' => 1 / 55,
];
const CHART_COLORS = ['#198754', '#20c997', '#36b9cc', '#ffc107', '#fd7e14', '#6c757d', '#343a40', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#20c997', '#0dcaf0'];

/**
 * 【V12.1 - 數量修正版】LCA核心計算引擎
 * @description 修正了製程計算邏輯，能正確處理非公斤單位的數量。
 */
function calculate_lca_from_bom(array $components, array $eol, array $materials_map, array $processes_map, array $use_phase_data = [], array $transport_data = [], array $grid_factors = []): array {
    $eol_recycle_pct = ($eol['recycle'] ?? 0) / 100;
    $eol_incinerate_pct = ($eol['incinerate'] ?? 0) / 100;
    $eol_landfill_pct = ($eol['landfill'] ?? 0) / 100;
    $transport_factors = json_decode(file_get_contents(__DIR__ . '/transport_factors.json'), true);
    $transport_routes = json_decode(file_get_contents(__DIR__ . '/transport_routes.json'), true);
    $totals = [
        'weight' => 0, 'recycled_weight' => 0, 'cost_current' => 0, 'cost_virgin' => 0,
        'co2' => 0, 'energy' => 0, 'water' => 0, 'acidification' => 0, 'eutrophication' => 0,
        'ozone_depletion' => 0, 'photochemical_ozone' => 0, 'adp' => 0,
        'co2_prod' => 0, 'co2_transport' => 0, 'co2_use' => 0, 'co2_process' => 0,
        'eol_impact' => 0, 'biogenic_carbon_sequestration' => 0,
        'waste' => 0, 'wastewater' => 0, 'water_withdrawal' => 0,
        'non_ghg_air' => 0, 'soil_pollutants' => 0, 'plastic_pollutants' => 0
    ];
    $virgin_only_totals = [ 'co2' => 0, 'energy' => 0, 'water' => 0, 'acidification' => 0, 'eutrophication' => 0, 'ozone_depletion' => 0, 'photochemical_ozone' => 0, 'adp' => 0, 'waste' => 0, 'wastewater' => 0, 'water_withdrawal' => 0, 'non_ghg_air' => 0, 'soil_pollutants' => 0, 'plastic_pollutants' => 0, 'biogenic_carbon_sequestration' => 0 ];
    $composition_data = []; $impact_by_material = []; $savings_by_material = [];
    $multi_criteria_impacts = [];
    $transport_enabled = $transport_data['enabled'] ?? false;
    $global_route_key = $transport_data['global_route'] ?? 'none';
    $grid_region = $use_phase_data['region'] ?? 'GLOBAL';
    $grid_factor = $grid_factors[$grid_region]['factor'] ?? 0.475;

    $component_weights = [];
    foreach($components as $index => $c){
        if(($c['componentType'] ?? 'material') === 'material'){
            $component_weights[$index] = (float)($c['weight'] ?? 0);
        }
    }

    foreach ($components as $c) {
        $componentType = $c['componentType'] ?? 'material';

        if ($componentType === 'process') {
            $processKey = $c['processKey'] ?? '';
            $appliedToIndices = $c['appliedToComponentIndices'] ?? [];

            if (empty($processKey) || empty($appliedToIndices) || !isset($processes_map[$processKey])) {
                continue;
            }

            $process = $processes_map[$processKey];
            $unit = $process['unit'] ?? 'kg';

            $totalMultiplier = 1.0;
            if (isset($c['selectedOptions']) && is_array($c['selectedOptions'])) {
                foreach ($c['selectedOptions'] as $optionKey => $itemKey) {
                    if (isset($process['options'][$optionKey])) {
                        foreach ($process['options'][$optionKey]['choices'] as $choice) {
                            if ($choice['key'] === $itemKey) {
                                $totalMultiplier *= (float)($choice['energy_multiplier'] ?? 1.0);
                                break;
                            }
                        }
                    }
                }
            }

            $baseEnergyKwh = (float)($process['energy_consumption_kwh'] ?? 0);
            $totalProcessCo2 = 0;
            $totalProcessEnergyMj = 0;

            foreach ($appliedToIndices as $targetIndex) {
                if (!isset($component_weights[$targetIndex])) {
                    continue;
                }
                $targetWeight = $component_weights[$targetIndex];

                // 決定計算數量
                $quantity_for_process = 1.0;
                if (strtolower($unit) === 'kg') {
                    // 如果單位是 kg，數量就等於作用物料的重量
                    $quantity_for_process = $targetWeight;
                } else {
                    // 否則，使用前端傳來的數量
                    $quantity_for_process = (float)($c['quantity'] ?? 1.0);
                }

                $processEnergyKwh = $baseEnergyKwh * $totalMultiplier * $quantity_for_process;

                $totalProcessCo2 += $processEnergyKwh * $grid_factor;
                $totalProcessEnergyMj += $processEnergyKwh * 3.6;
            }

            $totals['co2'] += $totalProcessCo2;
            $totals['co2_process'] += $totalProcessCo2;
            $totals['energy'] += $totalProcessEnergyMj;

            if ($totalProcessCo2 > 0) {
                $composition_data[] = [
                    'key' => $processKey,
                    'name' => $process['name'],
                    'weight' => 0,
                    'co2' => $totalProcessCo2,
                    'data_source' => $process['data_source'] ?? 'N/A',
                    'percentage' => null, 'cost' => 0, 'cost_virgin' => 0
                ];
            }

            continue;
        }

        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;
        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;

        $virgin_cost_from_db = $material['cost_per_kg'] ?? 0;
        $totals['cost_virgin'] += $weight * $virgin_cost_from_db;
        $cost_virgin_for_component = $weight * $virgin_cost_from_db;
        $final_cost_per_kg_for_current_design = 0;
        if (isset($c['cost']) && $c['cost'] !== '') {
            $final_cost_per_kg_for_current_design = (float)$c['cost'];
        } else {
            $co2_reduction_ratio = (($material['virgin_co2e_kg'] ?? 0) > 0) ? (($material['recycled_co2e_kg'] ?? 0) / $material['virgin_co2e_kg']) : 1;
            $approximated_recycled_cost = $virgin_cost_from_db * $co2_reduction_ratio;
            $final_cost_per_kg_for_current_design = ($virgin_cost_from_db * $virgin_ratio) + ($approximated_recycled_cost * $recycled_ratio);
        }
        $totals['cost_current'] += $weight * $final_cost_per_kg_for_current_design;
        $cost_current_for_component = $weight * $final_cost_per_kg_for_current_design;

        $totals['weight'] += $weight;
        $totals['recycled_weight'] += $weight * $recycled_ratio;

        $transport_co2 = 0;
        if ($transport_enabled) {
            $route_to_use = null;
            $component_route_info = $c['transportRoute'] ?? $global_route_key;
            if (is_string($component_route_info)) {
                $route_to_use = $transport_routes[$component_route_info] ?? null;
            } elseif (is_array($component_route_info) && !empty($component_route_info['legs'])) {
                $route_to_use = $component_route_info;
            }
            if ($route_to_use && !empty($route_to_use['legs'])) {
                foreach ($route_to_use['legs'] as $leg_index => $leg) {
                    $mode = $leg['mode'] ?? 'none';
                    $distance = (float)($c['transportOverrides']['legs'][$leg_index]['distance_km'] ?? $leg['distance_km'] ?? 0);
                    $emission_factor = (float)($transport_factors[$mode]['emission_factor'] ?? 0);
                    $transport_co2 += ($weight / 1000) * $distance * $emission_factor;
                }
            }
        }
        $totals['co2_transport'] += $transport_co2;

        $co2_prod = ($weight * $virgin_ratio * ($material['virgin_co2e_kg'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_co2e_kg'] ?? 0));
        $energy_prod = ($weight * $virgin_ratio * ($material['virgin_energy_mj_kg'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_energy_mj_kg'] ?? 0));
        $water_prod = ($weight * $virgin_ratio * ($material['virgin_water_l_kg'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_water_l_kg'] ?? 0));
        $acid_prod = ($weight * $virgin_ratio * ($material['acidification_kg_so2e'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_acidification_kg_so2e'] ?? 0));
        $eutro_prod = ($weight * $virgin_ratio * ($material['eutrophication_kg_po4e'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_eutrophication_kg_po4e'] ?? 0));
        $ozone_prod = ($weight * $virgin_ratio * ($material['ozone_depletion_kg_cfc11e'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_ozone_depletion_kg_cfc11e'] ?? 0));
        $photo_prod = ($weight * $virgin_ratio * ($material['photochemical_ozone_kg_nmvoce'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_photochemical_ozone_kg_nmvoce'] ?? 0));
        $adp_prod = ($weight * $virgin_ratio * ($material['virgin_adp_kgsbe'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_adp_kgsbe'] ?? 0));
        $waste_prod = ($weight * $virgin_ratio * ($material['virgin_waste_generated_kg'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_waste_generated_kg'] ?? 0));
        $wastewater_prod = ($weight * $virgin_ratio * ($material['virgin_wastewater_discharged_m3'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_wastewater_discharged_m3'] ?? 0));
        $water_withdrawal_prod = ($weight * $virgin_ratio * ($material['virgin_water_withdrawal_m3'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_water_withdrawal_m3'] ?? 0));
        $non_ghg_air_prod = ($weight * $virgin_ratio * ($material['virgin_non_ghg_air_pollutants_kg'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_non_ghg_air_pollutants_kg'] ?? 0));
        $soil_pollutants_prod = ($weight * $virgin_ratio * ($material['virgin_pollutants_to_soil_kg'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_pollutants_to_soil_kg'] ?? 0));
        $plastic_pollutants_prod = ($weight * $virgin_ratio * ($material['virgin_plastic_pollutants_kg'] ?? 0)) + ($weight * $recycled_ratio * ($material['recycled_plastic_pollutants_kg'] ?? 0));
        $eol_impact = $weight * (($eol_recycle_pct * ($material['eol_recycle_credit_co2e'] ?? 0)) + ($eol_incinerate_pct * ($material['eol_incinerate_co2e'] ?? 0)) + ($eol_landfill_pct * ($material['eol_landfill_co2e'] ?? 0)));
        $biogenic_carbon_content = (float)($material['biogenic_carbon_content_kg'] ?? 0);
        $biogenic_carbon_sequestration = $weight * $virgin_ratio * $biogenic_carbon_content * (44/12);
        $comp_co2 = $co2_prod - $biogenic_carbon_sequestration + $transport_co2 + $eol_impact;
        $totals['co2'] += $comp_co2; $totals['energy'] += $energy_prod; $totals['water'] += $water_prod;
        $totals['acidification'] += $acid_prod; $totals['eutrophication'] += $eutro_prod;
        $totals['ozone_depletion'] += $ozone_prod; $totals['photochemical_ozone'] += $photo_prod;
        $totals['adp'] += $adp_prod; $totals['co2_prod'] += $co2_prod; $totals['eol_impact'] += $eol_impact;
        $totals['biogenic_carbon_sequestration'] += $biogenic_carbon_sequestration;
        $totals['waste'] += $waste_prod; $totals['wastewater'] += $wastewater_prod; $totals['water_withdrawal'] += $water_withdrawal_prod;
        $totals['non_ghg_air'] += $non_ghg_air_prod; $totals['soil_pollutants'] += $soil_pollutants_prod; $totals['plastic_pollutants'] += $plastic_pollutants_prod;
        $virgin_only_totals['co2'] += ($weight * ($material['virgin_co2e_kg'] ?? 0)) - ($weight * $biogenic_carbon_content * (44/12)) + $eol_impact;
        $virgin_only_totals['energy'] += $weight * ($material['virgin_energy_mj_kg'] ?? 0);
        $virgin_only_totals['water'] += $weight * ($material['virgin_water_l_kg'] ?? 0);
        $virgin_only_totals['acidification'] += $weight * ($material['acidification_kg_so2e'] ?? 0);
        $virgin_only_totals['eutrophication'] += $weight * ($material['eutrophication_kg_po4e'] ?? 0);
        $virgin_only_totals['ozone_depletion'] += $weight * ($material['ozone_depletion_kg_cfc11e'] ?? 0);
        $virgin_only_totals['photochemical_ozone'] += $weight * ($material['photochemical_ozone_kg_nmvoce'] ?? 0);
        $virgin_only_totals['adp'] += $weight * ($material['virgin_adp_kgsbe'] ?? 0);
        $virgin_only_totals['waste'] += $weight * ($material['virgin_waste_generated_kg'] ?? 0);
        $virgin_only_totals['wastewater'] += $weight * ($material['virgin_wastewater_discharged_m3'] ?? 0);
        $virgin_only_totals['water_withdrawal'] += $weight * ($material['virgin_water_withdrawal_m3'] ?? 0);
        $virgin_only_totals['non_ghg_air'] += $weight * ($material['virgin_non_ghg_air_pollutants_kg'] ?? 0);
        $virgin_only_totals['soil_pollutants'] += $weight * ($material['virgin_pollutants_to_soil_kg'] ?? 0);
        $virgin_only_totals['plastic_pollutants'] += $weight * ($material['virgin_plastic_pollutants_kg'] ?? 0);
        $virgin_only_totals['biogenic_carbon_sequestration'] += $weight * $biogenic_carbon_content * (44/12);
        $multi_criteria_impacts[$material['name']] = ['co2' => $comp_co2, 'acidification' => $acid_prod, 'eutrophication' => $eutro_prod, 'ozone_depletion' => $ozone_prod, 'photochemical_ozone' => $photo_prod];
        $co2_prod_saved = ($weight * ($material['virgin_co2e_kg'] ?? 0)) - $co2_prod;
        $savings_by_material[$material['name']] = [ 'co2_from_virgin' => $weight * $virgin_ratio * ($material['virgin_co2e_kg'] ?? 0), 'co2_from_recycled' => $weight * $recycled_ratio * ($material['recycled_co2e_kg'] ?? 0), 'co2_saved' => $co2_prod_saved ];
        $composition_data[] = [ 'key' => $key, 'name' => $material['name'], 'weight' => $weight, 'co2' => $comp_co2, 'data_source' => $material['data_source'] ?? 'N/A', 'percentage' => $recycled_ratio * 100, 'cost' => $cost_current_for_component, 'cost_virgin' => $cost_virgin_for_component ];
        $impact_by_material[$material['name']] = ['co2' => $comp_co2, 'energy' => $energy_prod, 'water' => $water_prod];
    }

    if ($totals['weight'] <= 0) { return ['success' => false, 'error' => '無效的物料重量。']; }

    // 【修正處】
    $use_phase_enabled = $use_phase_data['enabled'] ?? false;

    if ($use_phase_enabled && isset($use_phase_data['lifespan'], $use_phase_data['kwh']) && is_numeric($use_phase_data['lifespan']) && is_numeric($use_phase_data['kwh'])) {
        $lifespan = (float)($use_phase_data['lifespan']);
        $annual_kwh = (float)($use_phase_data['kwh']);
        $totals['co2_use'] = $lifespan * $annual_kwh * $grid_factor;
        $totals['co2'] += $totals['co2_use'];
        $virgin_only_totals['co2'] += $totals['co2_use'];
        $annual_water = (float)($use_phase_data['water'] ?? 0);
        $totals['water'] += $lifespan * $annual_water;
        $virgin_only_totals['water'] += $lifespan * $annual_water;
    }

    $calculate_score = function($current, $virgin) { if (abs($virgin) < 1e-9) return ($current > 0) ? -100 : 100; $score = (($virgin - $current) / abs($virgin)) * 100; return max(-100, min(100, $score)); };
    $environmental_fingerprint_scores = [
        'co2' => $calculate_score($totals['co2'], $virgin_only_totals['co2']),
        'energy' => $calculate_score($totals['energy'], $virgin_only_totals['energy']),
        'water' => $calculate_score($totals['water'], $virgin_only_totals['water']),
        'acidification' => $calculate_score($totals['acidification'], $virgin_only_totals['acidification']),
        'eutrophication' => $calculate_score($totals['eutrophication'], $virgin_only_totals['eutrophication']),
        'ozone_depletion' => $calculate_score($totals['ozone_depletion'], $virgin_only_totals['ozone_depletion']),
        'photochemical_ozone' => $calculate_score($totals['photochemical_ozone'], $virgin_only_totals['photochemical_ozone'])
    ];
    $multi_criteria_hotspots_data = [];
    $impact_keys = ['co2', 'acidification', 'eutrophication', 'ozone_depletion', 'photochemical_ozone'];
    foreach ($impact_keys as $key) {
        $components_for_key = [];
        $total_impact_for_key = array_sum(array_column($multi_criteria_impacts, $key));
        if (abs($total_impact_for_key) < 1e-12) continue;
        foreach ($multi_criteria_impacts as $name => $impacts) {
            $components_for_key[] = ['name' => $name, 'value' => $impacts[$key], 'percent' => ($impacts[$key] / $total_impact_for_key) * 100];
        }
        usort($components_for_key, fn($a, $b) => abs($b['value']) <=> abs($a['value']));
        $multi_criteria_hotspots_data[$key] = ['total' => $total_impact_for_key, 'components' => array_slice($components_for_key, 0, 5)];
    }
    $co2_saved = $virgin_only_totals['co2'] - $totals['co2']; $energy_saved = $virgin_only_totals['energy'] - $totals['energy']; $water_saved = $virgin_only_totals['water'] - $totals['water']; $energy_saved_kwh = $energy_saved / 3.6;
    $full_equivalents = [ 'car_km' => $co2_saved * EQUIVALENTS['CAR_KM_PER_KG_CO2E'], 'tree_years' => $co2_saved * EQUIVALENTS['TREE_YEAR_PER_KG_CO2E'], 'flight_km' => $co2_saved * EQUIVALENTS['FLIGHT_KM_PER_KG_CO2E'], 'beef_kg' => $co2_saved * EQUIVALENTS['BEEF_KG_PER_KG_CO2E'], 'phone_charges' => $energy_saved_kwh * EQUIVALENTS['PHONE_CHARGES_PER_KWH'], 'fridge_days' => $energy_saved_kwh * EQUIVALENTS['FRIDGE_DAYS_PER_KWH'], 'led_bulb_hours' => $energy_saved_kwh * EQUIVALENTS['LED_BULB_HOURS_PER_KWH'], 'ac_hours' => $energy_saved_kwh * EQUIVALENTS['AC_HOURS_PER_KWH'], 'showers' => $water_saved * EQUIVALENTS['SHOWERS_PER_LITER'], 'toilet_flushes' => $water_saved * EQUIVALENTS['TOILET_FLUSHES_PER_LITER'], 'a4_sheets' => $water_saved * EQUIVALENTS['A4_SHEETS_PER_LITER'], 'washing_loads' => $water_saved * EQUIVALENTS['WASHING_LOADS_PER_LITER'], ];
    $total_virgin_weight = $totals['weight'] - $totals['recycled_weight'];

    $charts = [
        'composition' => $composition_data,
        'impact_by_material' => $impact_by_material,
        'savings_by_material' => $savings_by_material,
        'lifecycle_co2' => [
            'production' => round($totals['co2_prod'], 4),
            'process' => round($totals['co2_process'], 4),
            'transport' => round($totals['co2_transport'], 4),
            'use' => round($totals['co2_use'], 4),
            'eol' => round($totals['eol_impact'], 4),
            'sequestration' => round($totals['biogenic_carbon_sequestration'], 4)
        ],
        'content_by_type' => ['recycled' => round($totals['recycled_weight'], 4), 'virgin' => round($total_virgin_weight, 4)],
        'multi_criteria_hotspots' => $multi_criteria_hotspots_data
    ];

    return [
        'success' => true,
        'inputs' => ['components' => $components, 'totalWeight' => $totals['weight'], 'eol_scenario' => $eol],
        'impact' => [
            'co2' => round($totals['co2'], 5), 'energy' => round($totals['energy'], 3), 'water' => round($totals['water'], 3),
            'cost' => round($totals['cost_current'], 2), 'acidification' => $totals['acidification'], 'eutrophication' => $totals['eutrophication'],
            'ozone_depletion' => $totals['ozone_depletion'], 'photochemical_ozone' => $totals['photochemical_ozone'],
            'adp' => $totals['adp'], 'waste' => $totals['waste'], 'wastewater' => $totals['wastewater'], 'water_withdrawal' => $totals['water_withdrawal'],
            'non_ghg_air' => $totals['non_ghg_air'], 'soil_pollutants' => $totals['soil_pollutants'], 'plastic_pollutants' => $totals['plastic_pollutants'],
            'co2_use' => round($totals['co2_use'], 5)
        ],
        'virgin_impact' => [
            'co2' => round($virgin_only_totals['co2'], 5), 'energy' => round($virgin_only_totals['energy'], 3), 'water' => round($virgin_only_totals['water'], 3),
            'cost' => round($totals['cost_virgin'], 2), 'acidification' => $virgin_only_totals['acidification'], 'eutrophication' => $virgin_only_totals['eutrophication'],
            'ozone_depletion' => $virgin_only_totals['ozone_depletion'], 'photochemical_ozone' => $virgin_only_totals['photochemical_ozone'],
            'adp' => $virgin_only_totals['adp'], 'waste' => $virgin_only_totals['waste'], 'wastewater' => $virgin_only_totals['wastewater'], 'water_withdrawal' => $virgin_only_totals['water_withdrawal'],
            'non_ghg_air' => $virgin_only_totals['non_ghg_air'], 'soil_pollutants' => $virgin_only_totals['soil_pollutants'], 'plastic_pollutants' => $virgin_only_totals['plastic_pollutants']
        ],
        'charts' => $charts,
        'equivalents' => $full_equivalents,
        'environmental_fingerprint_scores' => $environmental_fingerprint_scores,
    ];
}

/**
 * 【V2.1 淨利升級版】商業效益分析引擎
 * @description 整合製造成本與管銷費用，計算更精準的淨利指標。
 */
function calculate_commercial_benefits(array $lca_result, float $selling_price, int $quantity, float $manufacturingCost, float $sgaCost): array
{
    if ($selling_price <= 0 || $quantity <= 0) {
        return ['success' => false, 'message' => '未提供有效的售價或生產數量。'];
    }

    // --- 核心數據提取 ---
    $actual_material_cost_per_unit = $lca_result['impact']['cost'] ?? 0;
    $benchmark_material_cost_per_unit = $lca_result['virgin_impact']['cost'] ?? 0;
    $actual_co2_per_unit = $lca_result['impact']['co2'] ?? 0;
    $other_costs_per_unit = $manufacturingCost + $sgaCost;

    // --- 【核心修改】單件效益計算 (基於總成本與淨利) ---
    $actual_total_cost_per_unit = $actual_material_cost_per_unit + $other_costs_per_unit;
    $net_profit_per_unit = $selling_price - $actual_total_cost_per_unit;
    $benchmark_profit_per_unit = $selling_price - ($benchmark_material_cost_per_unit + $other_costs_per_unit);
    $net_margin = ($selling_price > 0) ? ($net_profit_per_unit / $selling_price) * 100 : 0;

    // 綠色溢價維持只比較「材料」成本
    $green_premium_per_unit = $actual_material_cost_per_unit - $benchmark_material_cost_per_unit;

    // --- 進階策略指標 ---
    $profit_per_co2 = ($actual_co2_per_unit != 0) ? ($net_profit_per_unit / $actual_co2_per_unit) : null;

    return [
        'success' => true,
        // 【核心修改】回傳基於淨利的總體數據
        'total_revenue' => $selling_price * $quantity,
        'total_actual_cost' => $actual_total_cost_per_unit * $quantity,
        'total_net_profit' => $net_profit_per_unit * $quantity,
        // 單件分析數據
        'selling_price' => $selling_price,
        'actual_cost_per_unit' => $actual_total_cost_per_unit,
        'net_profit_per_unit' => $net_profit_per_unit,
        'net_margin' => $net_margin,
        // 永續策略影響分析
        'green_premium_per_unit' => $green_premium_per_unit,
        'benchmark_profit_per_unit' => $benchmark_profit_per_unit,
        // 效率指標
        'profit_per_co2' => $profit_per_co2,
    ];
}

/**
 * 【新增】從BOM數據中找出貢獻最大的環境熱點
 * @param array $composition_data - 來自LCA計算結果中的'charts'.'composition'陣列
 * @return string - 貢獻最大的組件名稱
 */
function find_environmental_hotspot(array $composition_data): string
{
    if (empty($composition_data)) {
        return '未定義'; // 如果沒有組件數據，回傳一個明確的字串
    }
    // 複製一份陣列進行排序，避免修改原始數據
    $components_copy = $composition_data;
    // 依據碳排(co2)從高到低排序
    usort($components_copy, fn($a, $b) => ($b['co2'] ?? 0) <=> ($a['co2'] ?? 0));
    // 回傳排序後第一個組件的名稱，如果名稱不存在則回傳 '未定義'
    return $components_copy[0]['name'] ?? '未定義';
}

/**
 * 【V6.1 專業重構版】根據核心指標，產生產品的永續定位分析 (PHP 版本)
 * @description 此版本重構了判斷邏輯，使其更清晰，同時完整保留了原有的16種詳細診斷結果。
 * @param array $metrics - 包含所有必要指標的陣列
 * @return array - 包含定位名稱、圖示、顏色和描述的陣列
 */
function generateProfileAnalysis_php(array $metrics): array
{
    // 使用 extract 將陣列的 key 轉為同名變數，方便底下直接使用
    extract($metrics);

    $has_trade_off = ($energy_reduction_pct < -5 || $water_reduction_pct < -5) && $co2_reduction_pct > 10;
    $is_all_negative = $co2_reduction_pct < -5 && $energy_reduction_pct < -5 && $water_reduction_pct < -5;

    // 定義等級閾值
    $co2_levels = ['ELITE' => 70, 'HIGH' => 40, 'MID' => 10];
    $recycled_levels = ['LEADER' => 70, 'HIGH' => 40, 'MID' => 10];
    $efficiency_levels = ['MID' => 60];

    // 定義診斷規則 (由上到下，優先級遞減)
    $rules = [
        // 1. 最高優先級的「絕對診斷」
        [
            'condition' => fn() => $co2_val < -0.001,
            'profile' => ['title' => '淨零碳移除典範', 'icon' => 'fa-star', 'color' => 'success', 'description' => '此設計實現了「碳移除」(Carbon Removal)的卓越成就，其生命週期的碳信用效益已超越排放量，是淨零排放的標竿。']
        ],
        [
            'condition' => fn() => $is_all_negative,
            'profile' => ['title' => '系統性衝擊惡化', 'icon' => 'fa-bomb', 'color' => 'danger', 'description' => '警告：此設計在所有核心環境指標上，均劣於100%原生料基準，顯示其存在系統性的設計缺陷。']
        ],
        [
            'condition' => fn() => $recycled_pct >= $recycled_levels['HIGH'] && $co2_reduction_pct < -10,
            'profile' => ['title' => '誤導性循環策略', 'icon' => 'fa-exclamation-triangle', 'color' => 'danger', 'description' => '嚴重警告：高比例的再生料投入，反而導致產品碳足跡顯著惡化，是典型的「偽生態設計」。']
        ],
        [
            'condition' => fn() => $has_trade_off,
            'profile' => ['title' => '顯著衝擊轉移', 'icon' => 'fa-balance-scale-left', 'color' => 'warning', 'description' => "此設計在實現減碳的同時，卻以顯著增加" . ($energy_reduction_pct < 0 ? '能源' : '') . ($water_reduction_pct < 0 ? ($energy_reduction_pct < 0 ? '和水資源' : '水資源') : '') . "消耗為代價，是典型的「衝擊轉移」現象。"]
        ],
        // 2. 矩陣式決策
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['ELITE'] && $recycled_pct >= $recycled_levels['LEADER'],
            'profile' => ['title' => '協同領導者', 'icon' => 'fa-award', 'color' => 'success', 'description' => '此設計在減碳與循環經濟兩方面均達到頂級水平，展現了教科書級別的「生態效益協同」。']
        ],
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['ELITE'] && $recycled_pct >= $recycled_levels['HIGH'],
            'profile' => ['title' => '高效減碳領導者', 'icon' => 'fa-rocket', 'color' => 'success', 'description' => '此設計達成了菁英級的減碳成效，同時奠定了堅實的循環經濟基礎，在永續道路上已大幅領先。']
        ],
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['ELITE'],
            'profile' => ['title' => '精粹脫鉤典範', 'icon' => 'fa-feather-alt', 'color' => 'primary', 'description' => '此設計在未高度依賴再生材料的情況下實現了菁英級的減碳，可能透過「輕量化」或「製程創新」達成了顯著的「資源脫鉤」。']
        ],
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['HIGH'] && $recycled_pct >= $recycled_levels['LEADER'] && $material_efficiency_score < $efficiency_levels['MID'],
            'profile' => ['title' => '循環轉型者 (效率瓶頸)', 'icon' => 'fa-cogs', 'color' => 'warning', 'description' => '雖已建立頂級的循環實踐，但其減碳貢獻未達預期，顯示「材料生態效益」存在顯著瓶頸。']
        ],
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['HIGH'] && $recycled_pct >= $recycled_levels['LEADER'],
            'profile' => ['title' => '循環轉型者 (潛力釋放中)', 'icon' => 'fa-cogs', 'color' => 'info', 'description' => '已成功建立頂級的循環經濟實踐，並將其轉化為顯著的減碳成果，下一步是將頂級循環度完全兌現為頂級減碳績效。']
        ],
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['HIGH'] && $recycled_pct >= $recycled_levels['HIGH'],
            'profile' => ['title' => '均衡進取型', 'icon' => 'fa-chart-line', 'color' => 'primary', 'description' => '此設計在減碳與循環度兩方面均取得了顯著且均衡的進展，展現了穩健的永續發展路徑。']
        ],
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['HIGH'],
            'profile' => ['title' => '機會主義脫鉤者', 'icon' => 'fa-lightbulb', 'color' => 'info', 'description' => '此設計在循環材料使用有限的情況下，依然取得了顯著的減碳成效，顯示您精準地捕捉到了某些「低垂的果實」。']
        ],
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['MID'] && $recycled_pct >= $recycled_levels['HIGH'],
            'profile' => ['title' => '循環先行者', 'icon' => 'fa-recycle', 'color' => 'info', 'description' => '您在循環經濟的實踐上走在了前沿，但在將其轉化為減碳效益方面尚有巨大潛力。']
        ],
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['MID'] && $recycled_pct >= $recycled_levels['MID'],
            'profile' => ['title' => '穩步改善者', 'icon' => 'fa-walking', 'color' => 'secondary', 'description' => '您的設計已展現出初步的、多方位的環境效益改善，是未來進行重點優化的良好開端。']
        ],
        [
            'condition' => fn() => $co2_reduction_pct >= $co2_levels['MID'],
            'profile' => ['title' => '初步改善者', 'icon' => 'fa-seedling', 'color' => 'secondary', 'description' => '您的設計已在減碳方面邁出了正向的第一步，為後續更大規模的迭代提供了寶貴經驗。']
        ],
        [
            'condition' => fn() => $recycled_pct >= $recycled_levels['HIGH'],
            'profile' => ['title' => '高投入低效益循環', 'icon' => 'fa-battery-quarter', 'color' => 'warning', 'description' => '您投入了大量的循環材料，但並未觀察到預期的減碳效益，反映出極低的「材料生態效益」。']
        ],
        [
            'condition' => fn() => $recycled_pct >= $recycled_levels['MID'],
            'profile' => ['title' => '行業基準水平', 'icon' => 'fa-flag', 'color' => 'secondary', 'description' => '目前的設計在環保表現上與傳統原生料基準相比沒有實質性差異，是一個中性的起點。']
        ],
        // 預設診斷
        [
            'condition' => fn() => true,
            'profile' => ['title' => '優化潛力巨大', 'icon' => 'fa-search', 'color' => 'warning', 'description' => '目前的設計在環保表現上劣於行業基準，存在明顯的提升空間，任何有效的優化行動都可能帶來顯著的改善效益。']
        ]
    ];

    // 依序尋找第一個符合條件的規則並回傳其 profile
    foreach ($rules as $rule) {
        if ($rule['condition']()) {
            return $rule['profile'];
        }
    }
    // 理論上不會執行到這裡，但作為備用
    return $rules[count($rules) - 1]['profile'];
}

/**
 * 【V6.3 簡化指標版】計算 BOM 的社會衝擊分數與指標
 * @description 核心修正：移除對特定供應商數據的依賴，聚焦於更通用的指標，並重新分配權重。
 */
function calculate_social_impact(array $components, array $materials_map, array $modifiers = []): array {
    global $social_risk_dictionary, $social_certification_dictionary;
    $total_weight = 0;
    $weighted_risk_sum = 0;
    $all_risks = [];
    $all_certs = [];
    $risk_contribution = [];
    $forced_labor_items = [];

    $sub_scores_weighted_sum = [
        'labor_practices' => 0,
        'health_safety' => 0,
        'human_rights' => 0
    ];

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;

        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $total_weight += $weight;
        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;

        // --- 1. 獲取簡化後的細項指標分數 ---
        $labor_risk = (float)($material['labor_practices_risk_score'] ?? 50);
        $health_risk = (float)($material['health_safety_risk_score'] ?? 50);

        $human_rights_risk = 0;
        if ($material['is_high_child_labor_risk'] ?? false) {
            $human_rights_risk += 50;
            $forced_labor_items[] = $material['name'] . ' (童工)';
        }
        if (($recycled_ratio < 1 ? $material['is_high_forced_labor_risk'] : $material['recycled_is_high_forced_labor_risk']) ?? false) {
            $human_rights_risk += 50;
            $forced_labor_items[] = $material['name'] . ' (強迫勞動)';
        }
        $human_rights_risk = min(100, $human_rights_risk);

        // --- 2. 【修正】使用新的權重加權計算綜合社會風險分數 ---
        $component_risk_score =
            ($labor_risk * 0.40) +          // 勞工實務 40%
            ($health_risk * 0.40) +         // 職業健康 40%
            ($human_rights_risk * 0.20);    // 人權風險 20%

        $risk_score = max(0, min(100, $component_risk_score));
        $weighted_risk_sum += $risk_score * $weight;

        // --- 3. 【修正】累加簡化後的細項加權值 ---
        $sub_scores_weighted_sum['labor_practices'] += $labor_risk * $weight;
        $sub_scores_weighted_sum['health_safety'] += $health_risk * $weight;
        $sub_scores_weighted_sum['human_rights'] += $human_rights_risk * $weight;

        $risk_keys = json_decode($material['known_risks'] ?? '[]', true);
        if (is_array($risk_keys)) { foreach($risk_keys as $risk_key) { if(isset($social_risk_dictionary[$risk_key])) { $all_risks[] = $social_risk_dictionary[$risk_key]; } } }
        $cert_keys = json_decode($material['certifications'] ?? '[]', true);
        if (is_array($cert_keys)) { foreach($cert_keys as $cert_key) { if(isset($social_certification_dictionary[$cert_key])) { $all_certs[] = $social_certification_dictionary[$cert_key]; } } }
        $risk_contribution[] = ['name' => $material['name'], 'risk_score' => $risk_score, 'weight' => $weight, 'weighted_risk' => $risk_score * $weight];
    }

    if ($total_weight <= 0) { return ['success' => false, 'error' => '無效的物料重量。']; }

    $overall_score = $weighted_risk_sum / $total_weight;
    foreach($risk_contribution as &$item) { $item['contribution_pct'] = ($weighted_risk_sum > 0) ? ($item['weighted_risk'] / $weighted_risk_sum * 100) : 0; }
    unset($item);
    usort($risk_contribution, fn($a, $b) => $b['contribution_pct'] <=> $a['contribution_pct']);

    return [
        'success' => true,
        'overall_risk_score' => round($overall_score, 1),
        // 【修正】回傳簡化後的子分數
        'sub_scores' => [
            '勞工實務' => round($sub_scores_weighted_sum['labor_practices'] / $total_weight, 1),
            '職業健康' => round($sub_scores_weighted_sum['health_safety'] / $total_weight, 1),
            '人權保障' => round($sub_scores_weighted_sum['human_rights'] / $total_weight, 1),
        ],
        'unique_risks' => array_values(array_unique($all_risks)),
        'unique_certifications' => array_values(array_unique($all_certs)),
        'risk_contribution' => $risk_contribution,
        'forced_labor_items' => array_values(array_unique($forced_labor_items))
    ];
}

/**
 * 【V6.3 簡化指標版】計算 BOM 的企業治理衝擊分數與指標
 * @description 核心修正：移除對特定供應商數據的依賴，聚焦於更通用的指標，並重新分配權重。
 */
function calculate_governance_impact(array $components, array $materials_map, array $modifiers = []): array {
    global $governance_risk_dictionary, $governance_positive_dictionary;
    $total_weight = 0;
    $weighted_risk_sum = 0;
    $all_risks = [];
    $all_positives = [];
    $risk_contribution = [];
    $conflict_mineral_items = [];
    $used_certifications = [];
    $critical_raw_material_items = [];

    $sub_scores_weighted_sum = [
        'ethics' => 0,
        'transparency' => 0,
        'resilience' => 0
    ];

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;

        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $total_weight += $weight;
        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;

        // --- 1. 獲取簡化後的細項指標分數 ---
        $ethics_risk = (float)($material['business_ethics_risk_score'] ?? 50);
        $transparency_risk = (float)($material['transparency_risk_score'] ?? 50);

        $resilience_risk = 0;
        if ($material['is_critical_raw_material'] ?? false) {
            $resilience_risk += 40;
            $critical_raw_material_items[] = $material['name'];
        }
        $conflict_status = ($recycled_ratio < 1) ? ($material['conflict_mineral_status'] ?? null) : ($material['recycled_conflict_mineral_status'] ?? null);
        if (!empty($conflict_status) && strtolower($conflict_status) !== 'recycled source') {
            $resilience_risk += 40;
            $conflict_mineral_items[] = ['name' => $material['name'], 'status' => $conflict_status];
        }
        if ($material['is_from_sanctioned_country'] ?? false) $resilience_risk += 20;
        $resilience_risk = min(100, $resilience_risk);

        // --- 2. 【修正】使用新的權重加權計算綜合治理風險分數 ---
        $component_risk_score =
            ($ethics_risk * 0.40) +             // 商業道德 40%
            ($transparency_risk * 0.40) +      // 資訊透明 40%
            ($resilience_risk * 0.20);         // 供應鏈韌性 20%

        if ($recycled_ratio > 0) {
            $certs = json_decode($material['recycled_content_certification'] ?? '[]', true);
            if (is_array($certs) && !empty($certs)) {
                $component_risk_score *= 0.9;
                $used_certifications = array_merge($used_certifications, $certs);
            }
        }

        $risk_score = max(0, min(100, $component_risk_score));
        $weighted_risk_sum += $risk_score * $weight;

        // --- 3. 【修正】累加簡化後的細項加權值 ---
        $sub_scores_weighted_sum['ethics'] += $ethics_risk * $weight;
        $sub_scores_weighted_sum['transparency'] += $transparency_risk * $weight;
        $sub_scores_weighted_sum['resilience'] += $resilience_risk * $weight;

        $risk_keys = json_decode($material['identified_risks'] ?? '[]', true);
        if (is_array($risk_keys)) { foreach($risk_keys as $risk_key) { if(isset($governance_risk_dictionary[$risk_key])) { $all_risks[] = $governance_risk_dictionary[$risk_key]; } } }
        $positive_keys = json_decode($material['positive_attributes'] ?? '[]', true);
        if (is_array($positive_keys)) { foreach($positive_keys as $positive_key) { if(isset($governance_positive_dictionary[$positive_key])) { $all_positives[] = $governance_positive_dictionary[$positive_key]; } } }
        $risk_contribution[] = ['name' => $material['name'], 'risk_score' => $risk_score, 'weight' => $weight, 'weighted_risk' => $risk_score * $weight];
    }

    if ($total_weight <= 0) { return ['success' => false, 'error' => '無效的物料重量。']; }

    $overall_score = $weighted_risk_sum / $total_weight;
    foreach($risk_contribution as &$item) { $item['contribution_pct'] = ($weighted_risk_sum > 0) ? ($item['weighted_risk'] / $weighted_risk_sum * 100) : 0; }
    unset($item);
    usort($risk_contribution, fn($a, $b) => $b['contribution_pct'] <=> $a['contribution_pct']);
    $all_positives = array_merge($all_positives, array_map(fn($cert) => "[已驗證再生料] " . $cert, $used_certifications));

    return [
        'success' => true,
        'overall_risk_score' => round($overall_score, 1),
        // 【修正】回傳簡化後的子分數
        'sub_scores' => [
            '商業道德' => round($sub_scores_weighted_sum['ethics'] / $total_weight, 1),
            '資訊透明' => round($sub_scores_weighted_sum['transparency'] / $total_weight, 1),
            '供應鏈韌性' => round($sub_scores_weighted_sum['resilience'] / $total_weight, 1),
        ],
        'unique_risks' => array_values(array_unique($all_risks)),
        'unique_positives' => array_values(array_unique($all_positives)),
        'risk_contribution' => $risk_contribution,
        'conflict_mineral_items' => $conflict_mineral_items,
        'critical_raw_material_items' => array_values(array_unique($critical_raw_material_items))
    ];
}

/**
 * 【V3.1 專家升級版 - 原名取代】計算產品的法規風險 (CBAM, 塑膠稅, SVHC, EUDR, UFLPA)
 * @description 新增對歐盟毀林法規(EUDR)與美國防止強迫維吾爾人勞動法(UFLPA)的風險識別。
 */
function calculate_regulatory_impact(array $components, array $lca_result, array $materials_map): array
{
    define('CBAM_PRICE_PER_TON_EUR', 85);
    define('PLASTIC_TAX_PER_TON_EUR', 800);
    define('EUR_TO_TWD_RATE', 35.0);

    $virgin_plastic_packaging_weight_kg = 0;
    $cbam_items = [];
    $svhc_items = [];
    $eudr_items = []; // 【新增】
    $uflpa_items = []; // 【新增】

    $component_co2_map = [];
    foreach ($lca_result['charts']['composition'] as $comp_result) {
        $component_co2_map[$comp_result['key']] = $comp_result['co2'];
    }

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;

        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;

        // CBAM
        if (!empty($material['cbam_category'])) {
            $cbam_items[] = ['name' => $material['name'], 'category' => $material['cbam_category'], 'co2e_kg' => $component_co2_map[$key] ?? 0];
        }

        // 塑膠稅
        if ($material['is_plastic_packaging']) {
            $virgin_plastic_packaging_weight_kg += $weight * $virgin_ratio;
        }

        // SVHC
        if ($material['contains_svhc']) {
            $svhc_items[] = $material['name'];
        }

        // 【新增】EUDR 檢查
        if (($recycled_ratio < 1 ? $material['is_from_high_deforestation_risk_area'] : $material['recycled_is_from_high_deforestation_risk_area']) ?? false) {
            $eudr_items[] = $material['name'];
        }

        // 【新增】UFLPA 檢查
        if (($recycled_ratio < 1 ? $material['is_high_forced_labor_risk'] : $material['recycled_is_high_forced_labor_risk']) ?? false) {
            $uflpa_items[] = $material['name'];
        }
    }

    $total_cbam_co2 = array_sum(array_column($cbam_items, 'co2e_kg'));
    $potential_cbam_cost_eur = ($total_cbam_co2 / 1000) * CBAM_PRICE_PER_TON_EUR;
    $potential_plastic_tax_eur = ($virgin_plastic_packaging_weight_kg / 1000) * PLASTIC_TAX_PER_TON_EUR;

    return [
        'success' => true,
        'cbam_cost_twd' => round($potential_cbam_cost_eur * EUR_TO_TWD_RATE, 2),
        'cbam_items' => $cbam_items,
        'plastic_tax_twd' => round($potential_plastic_tax_eur * EUR_TO_TWD_RATE, 2),
        'virgin_plastic_weight_kg' => round($virgin_plastic_packaging_weight_kg, 4),
        'svhc_items' => array_values(array_unique($svhc_items)),
        'eudr_items' => array_values(array_unique($eudr_items)), // 【新增】
        'uflpa_items' => array_values(array_unique($uflpa_items))  // 【新增】
    ];
}

/**
 * 【V6.1 毀林風險整合版】計算 BOM 的生物多樣性衝擊分數與指標
 * @description 整合「毀林風險」，對來自高風險區的材料進行分數懲罰。
 */
function calculate_biodiversity_impact(array $components, array $materials_map): array {
    // 【修正】在此處引入對應的字典檔全域變數
    global $biodiversity_risk_dictionary, $biodiversity_positive_action_dictionary;
    $total_weight = 0;
    $current_impact = ['land_use' => 0, 'ecotox' => 0];
    $virgin_impact = ['land_use' => 0, 'ecotox' => 0];
    $all_risks = [];
    $all_positives = [];
    $weighted_risks_sum = 0;
    $weighted_positives_sum = 0;
    $contribution = [];
    $deforestation_risk_items = [];

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;

        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;
        $total_weight += $weight;

        $is_high_risk = ($recycled_ratio < 1) ? ($material['is_from_high_deforestation_risk_area'] ?? false) : ($material['recycled_is_from_high_deforestation_risk_area'] ?? false);
        if ($is_high_risk) {
            $deforestation_risk_items[] = $material['name'];
        }

        $land_use = ($virgin_ratio * ($material['land_use_m2a_kg'] ?? 0)) + ($recycled_ratio * ($material['recycled_land_use_m2a_kg'] ?? 0));
        $ecotox = ($virgin_ratio * ($material['ecotox_ctue_kg'] ?? 0)) + ($recycled_ratio * ($material['recycled_ecotox_ctue_kg'] ?? 0));
        $current_impact['land_use'] += $land_use * $weight;
        $current_impact['ecotox'] += $ecotox * $weight;
        $virgin_impact['land_use'] += ($material['land_use_m2a_kg'] ?? 0) * $weight;
        $virgin_impact['ecotox'] += ($material['ecotox_ctue_kg'] ?? 0) * $weight;
        $contribution[] = ['name' => $material['name'], 'land_use' => $land_use * $weight, 'ecotox' => $ecotox * $weight];

        $recycled_benefit_multiplier = 1 - ($recycled_ratio * 0.9);

        // 【修正】將風險「鍵」轉換為「中文文字」
        $risk_keys = json_decode($material['biodiversity_risks'] ?? '[]', true);
        if (is_array($risk_keys)) {
            $weighted_risks_sum += count($risk_keys) * $weight * $recycled_benefit_multiplier;
            foreach ($risk_keys as $risk_key) {
                if (isset($biodiversity_risk_dictionary[$risk_key])) {
                    $all_risks[] = $biodiversity_risk_dictionary[$risk_key];
                }
            }
        }

        // 【修正】將正面行動「鍵」轉換為「中文文字」
        $positive_keys = json_decode($material['positive_actions'] ?? '[]', true);
        if (is_array($positive_keys)) {
            $weighted_positives_sum += count($positive_keys) * $weight * (1 + $recycled_ratio);
            foreach ($positive_keys as $positive_key) {
                if (isset($biodiversity_positive_action_dictionary[$positive_key])) {
                    $all_positives[] = $biodiversity_positive_action_dictionary[$positive_key];
                }
            }
        }
    }

    if ($total_weight <= 0) {
        return ['success' => false, 'error' => '無效重量。'];
    }

    $land_use_reduction_score = ($virgin_impact['land_use'] > 1e-9) ? max(0, min(100, (($virgin_impact['land_use'] - $current_impact['land_use']) / $virgin_impact['land_use']) * 100)) : 100;
    $ecotox_reduction_score = ($virgin_impact['ecotox'] > 1e-9) ? max(0, min(100, (($virgin_impact['ecotox'] - $current_impact['ecotox']) / $virgin_impact['ecotox']) * 100)) : 100;
    $pressure_score = ($land_use_reduction_score + $ecotox_reduction_score) / 2;
    $avg_risks = $weighted_risks_sum / $total_weight;
    $avg_positives = $weighted_positives_sum / $total_weight;
    $response_score = max(0, min(100, 50 + ($avg_positives * 10) - ($avg_risks * 15)));

    if (!empty($deforestation_risk_items)) {
        $response_score -= 25;
        $response_score = max(0, $response_score);
    }

    $performance_score = ($pressure_score * 0.7) + ($response_score * 0.3);

    usort($contribution, fn($a, $b) => ($b['land_use'] ?? 0) <=> ($a['land_use'] ?? 0));
    $land_use_hotspots = array_slice(array_filter($contribution, fn($c) => $c['land_use'] > 0), 0, 3);
    usort($contribution, fn($a, $b) => ($b['ecotox'] ?? 0) <=> ($a['ecotox'] ?? 0));
    $ecotox_hotspots = array_slice(array_filter($contribution, fn($c) => $c['ecotox'] > 0), 0, 3);

    return [
        'success' => true,
        'performance_score' => round($performance_score, 1),
        'sub_scores' => ['pressure_score' => round($pressure_score, 1), 'response_score' => round($response_score, 1)],
        'total_land_use_m2a' => round($current_impact['land_use'], 3),
        'virgin_land_use_m2a' => round($virgin_impact['land_use'], 3),
        'total_ecotox_ctue' => $current_impact['ecotox'],
        'virgin_ecotox_ctue' => $virgin_impact['ecotox'],
        'unique_risks' => array_values(array_unique($all_risks)),
        'positive_actions' => array_values(array_unique($all_positives)),
        'hotspots' => ['land_use' => $land_use_hotspots, 'ecotox' => $ecotox_hotspots],
        'deforestation_risk_items' => array_values(array_unique($deforestation_risk_items))
    ];
}

// index.php

/**
 * 【V2.0 供應來源國整合版】計算 BOM 的水資源短缺足跡 (AWARE)
 * @description 升級：找出熱點的同時，標記其主要供應來源國。
 */
function calculate_water_scarcity_impact(array $components, array $materials_map): array {
    $total_weight = 0;
    $current_impact = 0;
    $virgin_impact = 0;
    $contribution = [];

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;

        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;
        $total_weight += $weight;

        $impact_value = ($virgin_ratio * ($material['water_scarcity_m3_world_eq'] ?? 0)) + ($recycled_ratio * ($material['recycled_water_scarcity_m3_world_eq'] ?? 0));
        $current_impact += $impact_value * $weight;
        $virgin_impact += ($material['water_scarcity_m3_world_eq'] ?? 0) * $weight;

        // 【核心升級】找出該物料的主要來源國
        $source_country = '未知';
        if (!empty($material['country_of_origin'])) {
            $origins = json_decode($material['country_of_origin'], true);
            if (is_array($origins) && !empty($origins)) {
                // 假設第一個國家是主要來源
                $source_country = $origins[0]['country'] ?? '未知';
            }
        }

        $contribution[] = [
            'name' => $material['name'],
            'impact' => $impact_value * $weight,
            'source_country' => $source_country // 儲存來源國
        ];
    }

    if ($total_weight <= 0) {
        return ['success' => false, 'error' => '無效重量。'];
    }

    $performance_score = ($virgin_impact > 1e-9) ? max(0, min(100, (($virgin_impact - $current_impact) / $virgin_impact) * 100)) : 100;

    usort($contribution, fn($a, $b) => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));
    $hotspots = array_slice(array_filter($contribution, fn($c) => $c['impact'] > 0), 0, 3);

    return [
        'success' => true,
        'performance_score' => round($performance_score, 1),
        'total_impact_m3_world_eq' => round($current_impact, 3),
        'virgin_impact_m3_world_eq' => round($virgin_impact, 3),
        'hotspots' => $hotspots
    ];
}

// index.php

/**
 * 【V2.0 關鍵原料整合版】計算 BOM 的資源消耗潛力 (ADP)
 * @description 升級：找出熱點的同時，標記其是否為「關鍵原料」。
 */
function calculate_resource_depletion_impact(array $components, array $materials_map): array {
    $total_weight = 0;
    $current_impact = 0;
    $virgin_impact = 0;
    $contribution = [];

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;

        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;
        $total_weight += $weight;

        $impact_value = ($virgin_ratio * ($material['virgin_adp_kgsbe'] ?? 0)) + ($recycled_ratio * ($material['recycled_adp_kgsbe'] ?? 0));
        $current_impact += $impact_value * $weight;
        $virgin_impact += ($material['virgin_adp_kgsbe'] ?? 0) * $weight;

        // 【核心升級】在貢獻度陣列中，加入是否為關鍵原料的標記
        $contribution[] = [
            'name' => $material['name'],
            'impact' => $impact_value * $weight,
            'is_critical' => $material['is_critical_raw_material'] ?? false
        ];
    }

    if ($total_weight <= 0) {
        return ['success' => false, 'error' => '無效重量。'];
    }

    $performance_score = ($virgin_impact > 1e-9) ? max(0, min(100, (($virgin_impact - $current_impact) / $virgin_impact) * 100)) : 100;

    usort($contribution, fn($a, $b) => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));
    $hotspots = array_slice(array_filter($contribution, fn($c) => $c['impact'] > 0), 0, 3);

    return [
        'success' => true,
        'performance_score' => round($performance_score, 1),
        'total_impact_kgsbe' => $current_impact,
        'virgin_impact_kgsbe' => $virgin_impact,
        'hotspots' => $hotspots
    ];
}

/**
 * 【V2.1 專家洞察強化版】計算生產廢棄物衝擊
 * @description 升級：同時計算出每個組件的「絕對損耗率」，供前端 AI 洞察使用。
 */
function calculate_waste_impact(array $components, array $materials_map): array {
    $total_weight = 0;
    $current_impact = 0;
    $virgin_impact = 0;
    $contribution = []; // 這個陣列現在將包含更豐富的數據

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;

        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;
        $total_weight += $weight;

        // 計算當前設計下，此組件的「單位廢棄物率」(kg_waste / kg_material)
        $impact_rate_for_component = ($virgin_ratio * ($material['virgin_waste_generated_kg'] ?? 0)) + ($recycled_ratio * ($material['recycled_waste_generated_kg'] ?? 0));

        // 累加總廢棄物重量
        $current_impact += $impact_rate_for_component * $weight;

        // 累加基準廢棄物重量
        $virgin_impact += ($material['virgin_waste_generated_kg'] ?? 0) * $weight;

        // 【核心升級】在貢獻度陣列中，同時儲存總廢棄物重量(impact)和單位損耗率(rate)
        $contribution[] = [
            'name' => $material['name'],
            'impact' => $impact_rate_for_component * $weight, // 總貢獻重量
            'rate' => $impact_rate_for_component             // 單位損耗率
        ];
    }

    if ($total_weight <= 0) {
        return ['success' => false, 'error' => '無效重量。'];
    }

    $performance_score = ($virgin_impact > 1e-9) ? max(0, min(100, (($virgin_impact - $current_impact) / $virgin_impact) * 100)) : (($current_impact <= 1e-9) ? 100 : 0);

    // 排序與篩選熱點的邏輯不變
    usort($contribution, fn($a, $b) => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));
    $hotspots = array_slice(array_filter($contribution, fn($c) => $c['impact'] > 0), 0, 3);

    return [
        'success' => true,
        'performance_score' => round($performance_score, 1),
        'total_impact_kg' => round($current_impact, 3),
        'virgin_impact_kg' => round($virgin_impact, 3),
        'hotspots' => $hotspots // 這個 hotspots 陣列現在包含了 rate 數據
    ];
}

/**
 * 【V2.0 專家洞察強化版】計算總能源消耗 (CED) 衝擊
 * @description 升級：同時計算出每個組件的「能源強度」，供前端 AI 洞察使用。
 */
function calculate_energy_impact(array $components, array $materials_map): array {
    $current_impact = 0;
    $virgin_impact = 0;
    $contribution = [];

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;
        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;

        // 【核心升級】計算當前設計下，此組件的「單位能源強度」(MJ/kg)
        $intensity_for_component = ($virgin_ratio * ($material['virgin_energy_mj_kg'] ?? 0)) + ($recycled_ratio * ($material['recycled_energy_mj_kg'] ?? 0));

        $current_impact += $intensity_for_component * $weight;
        $virgin_impact += ($material['virgin_energy_mj_kg'] ?? 0) * $weight;

        // 【核心升級】在貢獻度陣列中，同時儲存總能耗(impact)和能源強度(intensity)
        $contribution[] = [
            'name' => $material['name'],
            'impact' => $intensity_for_component * $weight, // 總貢獻能耗
            'intensity' => $intensity_for_component        // 單位能源強度
        ];
    }

    $performance_score = ($virgin_impact > 1e-9) ? max(0, min(100, (($virgin_impact - $current_impact) / $virgin_impact) * 100)) : (($current_impact <= 1e-9) ? 100 : 0);
    usort($contribution, fn($a, $b) => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));
    $hotspots = array_slice(array_filter($contribution, fn($c) => $c['impact'] > 0), 0, 3);

    return [
        'success' => true,
        'performance_score' => round($performance_score, 1),
        'total_impact' => round($current_impact, 3),
        'virgin_impact' => round($virgin_impact, 3),
        'hotspots' => $hotspots // 這個 hotspots 陣列現在包含了 intensity 數據
    ];
}

/**
 * 【全新】計算酸化潛力 (AP) 衝擊
 */
function calculate_acidification_impact(array $components, array $materials_map): array {
    $current_impact = 0;
    $virgin_impact = 0;
    $contribution = [];
    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;
        $material = $materials_map[$key] ?? null;
        if (!$material) continue;
        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;
        $impact_density = ($virgin_ratio * ($material['acidification_kg_so2e'] ?? 0)) + ($recycled_ratio * ($material['recycled_acidification_kg_so2e'] ?? 0));
        $current_impact += $impact_density * $weight;
        $virgin_impact += ($material['acidification_kg_so2e'] ?? 0) * $weight;
        $contribution[] = ['name' => $material['name'], 'impact' => $impact_density * $weight, 'density' => $impact_density];
    }
    $performance_score = ($virgin_impact > 1e-12) ? max(0, min(100, (($virgin_impact - $current_impact) / $virgin_impact) * 100)) : (($current_impact <= 1e-12) ? 100 : 0);
    usort($contribution, fn($a, $b) => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));
    $hotspots = array_slice(array_filter($contribution, fn($c) => $c['impact'] > 0), 0, 3);
    return ['success' => true, 'performance_score' => round($performance_score, 1), 'total_impact' => $current_impact, 'virgin_impact' => $virgin_impact, 'hotspots' => $hotspots];
}

/**
 * 【全新】計算優養化潛力 (EP) 衝擊
 */
function calculate_eutrophication_impact(array $components, array $materials_map): array {
    $current_impact = 0;
    $virgin_impact = 0;
    $contribution = [];
    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;
        $material = $materials_map[$key] ?? null;
        if (!$material) continue;
        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;
        $impact_density = ($virgin_ratio * ($material['eutrophication_kg_po4e'] ?? 0)) + ($recycled_ratio * ($material['recycled_eutrophication_kg_po4e'] ?? 0));
        $current_impact += $impact_density * $weight;
        $virgin_impact += ($material['eutrophication_kg_po4e'] ?? 0) * $weight;
        $contribution[] = ['name' => $material['name'], 'impact' => $impact_density * $weight, 'density' => $impact_density];
    }
    $performance_score = ($virgin_impact > 1e-12) ? max(0, min(100, (($virgin_impact - $current_impact) / $virgin_impact) * 100)) : (($current_impact <= 1e-12) ? 100 : 0);
    usort($contribution, fn($a, $b) => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));
    $hotspots = array_slice(array_filter($contribution, fn($c) => $c['impact'] > 0), 0, 3);
    return ['success' => true, 'performance_score' => round($performance_score, 1), 'total_impact' => $current_impact, 'virgin_impact' => $virgin_impact, 'hotspots' => $hotspots];
}

/**
 * 【全新】計算臭氧層破壞潛力 (ODP) 衝擊
 */
function calculate_ozone_depletion_impact(array $components, array $materials_map): array {
    $current_impact = 0;
    $virgin_impact = 0;
    $contribution = [];
    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;
        $material = $materials_map[$key] ?? null;
        if (!$material) continue;
        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;
        $impact_density = ($virgin_ratio * ($material['ozone_depletion_kg_cfc11e'] ?? 0)) + ($recycled_ratio * ($material['recycled_ozone_depletion_kg_cfc11e'] ?? 0));
        $current_impact += $impact_density * $weight;
        $virgin_impact += ($material['ozone_depletion_kg_cfc11e'] ?? 0) * $weight;
        $contribution[] = ['name' => $material['name'], 'impact' => $impact_density * $weight, 'density' => $impact_density];
    }
    $performance_score = ($virgin_impact > 1e-12) ? max(0, min(100, (($virgin_impact - $current_impact) / $virgin_impact) * 100)) : (($current_impact <= 1e-12) ? 100 : 0);
    usort($contribution, fn($a, $b) => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));
    $hotspots = array_slice(array_filter($contribution, fn($c) => $c['impact'] > 0), 0, 3);
    return ['success' => true, 'performance_score' => round($performance_score, 1), 'total_impact' => $current_impact, 'virgin_impact' => $virgin_impact, 'hotspots' => $hotspots];
}

/**
 * 【全新】計算光化學煙霧潛力 (POCP) 衝擊
 */
function calculate_photochemical_ozone_impact(array $components, array $materials_map): array {
    $current_impact = 0;
    $virgin_impact = 0;
    $contribution = [];
    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;
        $material = $materials_map[$key] ?? null;
        if (!$material) continue;
        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $virgin_ratio = 1 - $recycled_ratio;
        $impact_density = ($virgin_ratio * ($material['photochemical_ozone_kg_nmvoce'] ?? 0)) + ($recycled_ratio * ($material['recycled_photochemical_ozone_kg_nmvoce'] ?? 0));
        $current_impact += $impact_density * $weight;
        $virgin_impact += ($material['photochemical_ozone_kg_nmvoce'] ?? 0) * $weight;
        $contribution[] = ['name' => $material['name'], 'impact' => $impact_density * $weight, 'density' => $impact_density];
    }
    $performance_score = ($virgin_impact > 1e-12) ? max(0, min(100, (($virgin_impact - $current_impact) / $virgin_impact) * 100)) : (($current_impact <= 1e-12) ? 100 : 0);
    usort($contribution, fn($a, $b) => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));
    $hotspots = array_slice(array_filter($contribution, fn($c) => $c['impact'] > 0), 0, 3);
    return ['success' => true, 'performance_score' => round($performance_score, 1), 'total_impact' => $current_impact, 'virgin_impact' => $virgin_impact, 'hotspots' => $hotspots];
}

/** * 【V2.0 專家洞察強化版】產生總能源消耗 (CED) 計分卡的 HTML
 */
function generate_energy_scorecard_html(array $data): string {
    // 【核心升級】將原本的內部通用函式 _generate_generic_impact_scorecard_html 的邏輯直接整合進來並客製化
    if (!isset($data['success']) || !$data['success']) return '';

    $performance_score = $data['performance_score'] ?? 0;
    $total_impact = $data['total_impact'] ?? 0;
    $virgin_impact = $data['virgin_impact'] ?? 0;
    $hotspots = $data['hotspots'] ?? [];

    $scoreColorClass = 'text-success';
    if ($performance_score < 40) { $scoreColorClass = 'text-danger'; }
    else if ($performance_score < 70) { $scoreColorClass = 'text-warning'; }

    $totalHotspotImpact = array_reduce($hotspots, fn($sum, $item) => $sum + ($item['impact'] ?? 0), 0);
    $hotspotsHtml = $totalHotspotImpact > 1e-9 ? implode('', array_map(function($item) use ($totalHotspotImpact) {
        $contributionPct = (($item['impact'] ?? 0) / $totalHotspotImpact) * 100;
        $itemName = htmlspecialchars($item['name']);
        return "<div><div class='d-flex justify-content-between small'><span>{$itemName}</span><span class='fw-bold'>" . number_format($contributionPct, 1) . "%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-secondary' style='width: {$contributionPct}%;'></div></div></div>";
    }, $hotspots)) : '<div class="text-muted small">無顯著熱點。</div>';

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $hotspot = !empty($hotspots) ? $hotspots[0] : null;
    $insight = '';
    if ($performance_score < 40 && $hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $hotspotIntensity = number_format($hotspot['intensity'] ?? 0, 1);
        $insight = "<strong>策略警示：</strong>產品的「隱含能源」<strong class=\"text-danger\">過高</strong>。主要來源是「{$hotspotName}」，其自身的能源強度高達 <strong>{$hotspotIntensity} MJ/kg</strong>，代表其製程或原料開採為高耗能類型。";
    } elseif ($hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $hotspotIntensity = number_format($hotspot['intensity'] ?? 0, 1);
        $insight = "<strong>策略定位：</strong>主要的節能機會點在於優化「{$hotspotName}」，其能源強度為 <strong>{$hotspotIntensity} MJ/kg</strong>。尋找製程更高效的替代材料，將是提升分數的關鍵。";
    } else {
        $insight = "<strong>策略總評：</strong>產品在能源消耗上表現優異，具備良好的能源效率。";
    }

    $sdg_html = generateSdgIconsHtml([7, 12, 13]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center"><i class="fas fa-bolt text-primary me-2"></i>總能源消耗 (CED) 計分卡<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>
            {$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="energy-scorecard" title="這代表什麼？"></i>
        </div>
        <div class="card-body d-flex flex-column">
            <div class="row g-4">
                <div class="col-lg-4 text-center border-end">
                    <h6 class="text-muted">改善分數</h6>
                    <div class="display-4 fw-bold {$scoreColorClass}">{$performance_score}</div>
                </div>
                <div class="col-lg-8">
                    <div class="p-2 bg-light-subtle rounded-3 mb-3">
                        <div class="row text-center gx-1">
                            <div class="col-6 border-end"><small class="text-muted">原生料基準</small><p class="fw-bold mb-0">{$virgin_impact}<small> MJ</small></p></div>
                            <div class="col-6"><small class="text-muted">當前設計</small><p class="fw-bold mb-0 {$scoreColorClass}">{$total_impact}<small> MJ</small></p></div>
                        </div>
                    </div>
                    <h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要貢獻來源 (Top 3)</h6><div class="d-flex flex-column gap-2 mt-2">{$hotspotsHtml}</div>
                </div>
            </div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3 mt-auto"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div></div></div>
HTML;
}

/** 【V2.0 專家洞察強化版】產生酸化潛力 (AP) 計分卡的 HTML */
function generate_acidification_scorecard_html(array $data): string {
    if (!isset($data['success']) || !$data['success']) return '';
    $performance_score = $data['performance_score'] ?? 0;
    $total_impact = $data['total_impact'] ?? 0;
    $virgin_impact = $data['virgin_impact'] ?? 0;
    $hotspots = $data['hotspots'] ?? [];

    $scoreColorClass = 'text-success';
    if ($performance_score < 40) { $scoreColorClass = 'text-danger'; }
    else if ($performance_score < 70) { $scoreColorClass = 'text-warning'; }

    $totalHotspotImpact = array_reduce($hotspots, fn($sum, $item) => $sum + ($item['impact'] ?? 0), 0);
    $hotspotsHtml = $totalHotspotImpact > 1e-12 ? implode('', array_map(function($item) use ($totalHotspotImpact) {
        $contributionPct = (($item['impact'] ?? 0) / $totalHotspotImpact) * 100;
        $itemName = htmlspecialchars($item['name']);
        return "<div><div class='d-flex justify-content-between small'><span>{$itemName}</span><span class='fw-bold'>" . number_format($contributionPct, 1) . "%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-secondary' style='width: {$contributionPct}%;'></div></div></div>";
    }, $hotspots)) : '<div class="text-muted small">無顯著熱點。</div>';

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $hotspot = !empty($hotspots) ? $hotspots[0] : null;
    $insight = '';
    if ($performance_score < 40 && $hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $density = $hotspot['density'] ?? 0;
        // 建立風險等級判斷 (AP 閾值)
        $risk_level = '低'; $risk_color = 'success';
        if ($density > 0.05) { $risk_level = '極高'; $risk_color = 'danger'; }
        elseif ($density > 0.02) { $risk_level = '高'; $risk_color = 'danger'; }
        elseif ($density > 0.005) { $risk_level = '中'; $risk_color = 'warning'; }
        $insight = "<strong>策略警示：</strong>產品的「酸化潛力」<strong class=\"text-danger\">過高</strong>。熱點「{$hotspotName}」的衝擊密度經評估為「<strong class=\"text-{$risk_color}\">{$risk_level}風險</strong>」等級，可能與高污染的能源使用或運輸方式有關。";
    } elseif ($hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $insight = "<strong>策略定位：</strong>主要的改善機會點在於優化「{$hotspotName}」的供應鏈，特別是其能源結構與運輸路徑。";
    } else {
        $insight = "<strong>策略總評：</strong>產品在酸化潛力上表現優異，未發現顯著的單一污染熱點。";
    }

    $sdg_html = generateSdgIconsHtml([11, 14, 15]);
    $unit = 'kg SO₂e';
    $cardTitle = '酸化潛力 (AP) 計分卡';
    $iconClass = 'fa-cloud-rain';
    $dataTopic = 'acidification-scorecard';

    return <<<HTML
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas {$iconClass} text-primary me-2"></i>{$cardTitle}<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>{$sdg_html}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="{$dataTopic}" title="這代表什麼？"></i></div>
        <div class="card-body d-flex flex-column"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">改善分數</h6><div class="display-4 fw-bold {$scoreColorClass}">{$performance_score}</div></div>
                <div class="col-lg-8"><div class="p-2 bg-light-subtle rounded-3 mb-3"><div class="row text-center gx-1"><div class="col-6 border-end"><small class="text-muted">原生料基準</small><p class="fw-bold mb-0">{$virgin_impact}<small> {$unit}</small></p></div><div class="col-6"><small class="text-muted">當前設計</small><p class="fw-bold mb-0 {$scoreColorClass}">{$total_impact}<small> {$unit}</small></p></div></div></div>
                    <h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要貢獻來源 (Top 3)</h6><div class="d-flex flex-column gap-2 mt-2">{$hotspotsHtml}</div></div></div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3 mt-auto"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div></div></div>
HTML;
}

/** 【V2.0 專家洞察強化版】產生優養化潛力 (EP) 計分卡的 HTML */
function generate_eutrophication_scorecard_html(array $data): string {
    if (!isset($data['success']) || !$data['success']) return '';
    $performance_score = $data['performance_score'] ?? 0;
    $total_impact = $data['total_impact'] ?? 0;
    $virgin_impact = $data['virgin_impact'] ?? 0;
    $hotspots = $data['hotspots'] ?? [];

    $scoreColorClass = 'text-success';
    if ($performance_score < 40) { $scoreColorClass = 'text-danger'; }
    else if ($performance_score < 70) { $scoreColorClass = 'text-warning'; }

    $totalHotspotImpact = array_reduce($hotspots, fn($sum, $item) => $sum + ($item['impact'] ?? 0), 0);
    $hotspotsHtml = $totalHotspotImpact > 1e-12 ? implode('', array_map(function($item) use ($totalHotspotImpact) {
        $contributionPct = (($item['impact'] ?? 0) / $totalHotspotImpact) * 100;
        $itemName = htmlspecialchars($item['name']);
        return "<div><div class='d-flex justify-content-between small'><span>{$itemName}</span><span class='fw-bold'>" . number_format($contributionPct, 1) . "%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-secondary' style='width: {$contributionPct}%;'></div></div></div>";
    }, $hotspots)) : '<div class="text-muted small">無顯著熱點。</div>';

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $hotspot = !empty($hotspots) ? $hotspots[0] : null;
    $insight = '';
    if ($performance_score < 40 && $hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $density = $hotspot['density'] ?? 0;
        // 建立風險等級判斷 (EP 閾值)
        $risk_level = '低'; $risk_color = 'success';
        if ($density > 0.01) { $risk_level = '極高'; $risk_color = 'danger'; }
        elseif ($density > 0.005) { $risk_level = '高'; $risk_color = 'danger'; }
        elseif ($density > 0.001) { $risk_level = '中'; $risk_color = 'warning'; }
        $insight = "<strong>策略警示：</strong>產品的「優養化潛力」<strong class=\"text-danger\">過高</strong>。熱點「{$hotspotName}」的衝擊密度經評估為「<strong class=\"text-{$risk_color}\">{$risk_level}風險</strong>」等級，可能與農業原料（肥料流失）或工業廢水排放有關。";
    } elseif ($hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $insight = "<strong>策略定位：</strong>主要的改善機會點在於針對「{$hotspotName}」進行溯源，確保其來自永續農業或有良好廢水處理的供應商。";
    } else {
        $insight = "<strong>策略總評：</strong>產品在優養化潛力上表現優異，對水體生態衝擊低。";
    }

    $sdg_html = generateSdgIconsHtml([6, 14]);
    $unit = 'kg PO₄e';
    $cardTitle = '優養化潛力 (EP) 計分卡';
    $iconClass = 'fa-seedling';
    $dataTopic = 'eutrophication-scorecard';

    return <<<HTML
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas {$iconClass} text-primary me-2"></i>{$cardTitle}<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>{$sdg_html}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="{$dataTopic}" title="這代表什麼？"></i></div>
        <div class="card-body d-flex flex-column"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">改善分數</h6><div class="display-4 fw-bold {$scoreColorClass}">{$performance_score}</div></div>
                <div class="col-lg-8"><div class="p-2 bg-light-subtle rounded-3 mb-3"><div class="row text-center gx-1"><div class="col-6 border-end"><small class="text-muted">原生料基準</small><p class="fw-bold mb-0">{$virgin_impact}<small> {$unit}</small></p></div><div class="col-6"><small class="text-muted">當前設計</small><p class="fw-bold mb-0 {$scoreColorClass}">{$total_impact}<small> {$unit}</small></p></div></div></div>
                    <h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要貢獻來源 (Top 3)</h6><div class="d-flex flex-column gap-2 mt-2">{$hotspotsHtml}</div></div></div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3 mt-auto"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div></div></div>
HTML;
}

/** 【V2.0 專家洞察強化版】產生臭氧層破壞 (ODP) 計分卡的 HTML */
function generate_ozone_depletion_scorecard_html(array $data): string {
    if (!isset($data['success']) || !$data['success']) return '';
    $performance_score = $data['performance_score'] ?? 0;
    $total_impact = $data['total_impact'] ?? 0;
    $virgin_impact = $data['virgin_impact'] ?? 0;
    $hotspots = $data['hotspots'] ?? [];

    $scoreColorClass = 'text-success';
    if ($performance_score < 40) { $scoreColorClass = 'text-danger'; }
    else if ($performance_score < 70) { $scoreColorClass = 'text-warning'; }

    $totalHotspotImpact = array_reduce($hotspots, fn($sum, $item) => $sum + ($item['impact'] ?? 0), 0);
    $hotspotsHtml = $totalHotspotImpact > 1e-12 ? implode('', array_map(function($item) use ($totalHotspotImpact) {
        $contributionPct = (($item['impact'] ?? 0) / $totalHotspotImpact) * 100;
        $itemName = htmlspecialchars($item['name']);
        return "<div><div class='d-flex justify-content-between small'><span>{$itemName}</span><span class='fw-bold'>" . number_format($contributionPct, 1) . "%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-secondary' style='width: {$contributionPct}%;'></div></div></div>";
    }, $hotspots)) : '<div class="text-muted small">無顯著熱點。</div>';

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $hotspot = !empty($hotspots) ? $hotspots[0] : null;
    $insight = '';
    if ($performance_score < 70 && $hotspot) { // ODP 的標準更嚴格
        $hotspotName = htmlspecialchars($hotspot['name']);
        $density = $hotspot['density'] ?? 0;
        // 建立風險等級判斷 (ODP 閾值, 使用科學記號)
        $risk_level = '低'; $risk_color = 'success';
        if ($density > 1.0E-7) { $risk_level = '極高'; $risk_color = 'danger'; }
        elseif ($density > 1.0E-8) { $risk_level = '高'; $risk_color = 'danger'; }
        elseif ($density > 1.0E-9) { $risk_level = '中'; $risk_color = 'warning'; }
        $insight = "<strong>策略警示：</strong>產品偵測到「臭氧層破壞潛力」。熱點「{$hotspotName}」的衝擊密度經評估為「<strong class=\"text-{$risk_color}\">{$risk_level}風險</strong>」等級，可能代表供應鏈中存在使用禁用物質的<strong class=\"text-danger\">嚴重合規風險</strong>。";
    } elseif ($hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $insight = "<strong>策略定位：</strong>雖然衝擊量不大，但仍建議針對「{$hotspotName}」進行供應鏈調查，確保其製程中不含任何受《蒙特婁議定書》管制的化學物質。";
    } else {
        $insight = "<strong>策略總評：</strong>產品在臭氧層破壞潛力上符合國際標準，無顯著風險。";
    }

    $sdg_html = generateSdgIconsHtml([3, 13]);
    $unit = 'kg CFC-11e';
    $cardTitle = '臭氧層破壞 (ODP) 計分卡';
    $iconClass = 'fa-globe';
    $dataTopic = 'ozone-depletion-scorecard';

    return <<<HTML
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas {$iconClass} text-primary me-2"></i>{$cardTitle}<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>{$sdg_html}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="{$dataTopic}" title="這代表什麼？"></i></div>
        <div class="card-body d-flex flex-column"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">改善分數</h6><div class="display-4 fw-bold {$scoreColorClass}">{$performance_score}</div></div>
                <div class="col-lg-8"><div class="p-2 bg-light-subtle rounded-3 mb-3"><div class="row text-center gx-1"><div class="col-6 border-end"><small class="text-muted">原生料基準</small><p class="fw-bold mb-0">{$virgin_impact}<small> {$unit}</small></p></div><div class="col-6"><small class="text-muted">當前設計</small><p class="fw-bold mb-0 {$scoreColorClass}">{$total_impact}<small> {$unit}</small></p></div></div></div>
                    <h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要貢獻來源 (Top 3)</h6><div class="d-flex flex-column gap-2 mt-2">{$hotspotsHtml}</div></div></div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3 mt-auto"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div></div></div>
HTML;
}

/** 【V2.0 專家洞察強化版】產生光化學煙霧 (POCP) 計分卡的 HTML */
function generate_photochemical_ozone_scorecard_html(array $data): string {
    if (!isset($data['success']) || !$data['success']) return '';
    $performance_score = $data['performance_score'] ?? 0;
    $total_impact = $data['total_impact'] ?? 0;
    $virgin_impact = $data['virgin_impact'] ?? 0;
    $hotspots = $data['hotspots'] ?? [];

    $scoreColorClass = 'text-success';
    if ($performance_score < 40) { $scoreColorClass = 'text-danger'; }
    else if ($performance_score < 70) { $scoreColorClass = 'text-warning'; }

    $totalHotspotImpact = array_reduce($hotspots, fn($sum, $item) => $sum + ($item['impact'] ?? 0), 0);
    $hotspotsHtml = $totalHotspotImpact > 1e-12 ? implode('', array_map(function($item) use ($totalHotspotImpact) {
        $contributionPct = (($item['impact'] ?? 0) / $totalHotspotImpact) * 100;
        $itemName = htmlspecialchars($item['name']);
        return "<div><div class='d-flex justify-content-between small'><span>{$itemName}</span><span class='fw-bold'>" . number_format($contributionPct, 1) . "%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-secondary' style='width: {$contributionPct}%;'></div></div></div>";
    }, $hotspots)) : '<div class="text-muted small">無顯著熱點。</div>';

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $hotspot = !empty($hotspots) ? $hotspots[0] : null;
    $insight = '';
    if ($performance_score < 40 && $hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $density = $hotspot['density'] ?? 0;
        // 建立風險等級判斷 (POCP 閾值)
        $risk_level = '低'; $risk_color = 'success';
        if ($density > 0.02) { $risk_level = '極高'; $risk_color = 'danger'; }
        elseif ($density > 0.01) { $risk_level = '高'; $risk_color = 'danger'; }
        elseif ($density > 0.005) { $risk_level = '中'; $risk_color = 'warning'; }
        $insight = "<strong>策略警示：</strong>產品的「光化學煙霧潛力」<strong class=\"text-danger\">過高</strong>。熱點「{$hotspotName}」的衝擊密度經評估為「<strong class=\"text-{$risk_color}\">{$risk_level}風險</strong>」等級，可能涉及高揮發性有機化合物(VOCs)的製程。";
    } elseif ($hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $insight = "<strong>策略定位：</strong>主要的改善機會點在於針對「{$hotspotName}」，尋找使用低VOC或水性塗料/黏著劑的替代方案。";
    } else {
        $insight = "<strong>策略總評：</strong>產品在光化學煙霧潛力上表現良好，對空氣品質衝擊低。";
    }

    $sdg_html = generateSdgIconsHtml([3, 11]);
    $unit = 'kg NMVOCe';
    $cardTitle = '光化學煙霧 (POCP) 計分卡';
    $iconClass = 'fa-sun';
    $dataTopic = 'pocp-scorecard';

    return <<<HTML
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas {$iconClass} text-primary me-2"></i>{$cardTitle}<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>{$sdg_html}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="{$dataTopic}" title="這代表什麼？"></i></div>
        <div class="card-body d-flex flex-column"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">改善分數</h6><div class="display-4 fw-bold {$scoreColorClass}">{$performance_score}</div></div>
                <div class="col-lg-8"><div class="p-2 bg-light-subtle rounded-3 mb-3"><div class="row text-center gx-1"><div class="col-6 border-end"><small class="text-muted">原生料基準</small><p class="fw-bold mb-0">{$virgin_impact}<small> {$unit}</small></p></div><div class="col-6"><small class="text-muted">當前設計</small><p class="fw-bold mb-0 {$scoreColorClass}">{$total_impact}<small> {$unit}</small></p></div></div></div>
                    <h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要貢獻來源 (Top 3)</h6><div class="d-flex flex-column gap-2 mt-2">{$hotspotsHtml}</div></div></div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3 mt-auto"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div></div></div>
HTML;
}

/**
 * 【V2.1 專家洞察強化版】產生生產廢棄物計分卡的 HTML
 */
function generate_waste_scorecard_html(array $wasteData): string {
    if (!isset($wasteData['success']) || !$wasteData['success']) {
        return '';
    }

    $performance_score = $wasteData['performance_score'] ?? 0;
    $total_impact_kg = $wasteData['total_impact_kg'] ?? 0;
    $virgin_impact_kg = $wasteData['virgin_impact_kg'] ?? 0;
    $hotspots = $wasteData['hotspots'] ?? [];

    $scoreColorClass = 'text-success';
    if ($performance_score < 40) { $scoreColorClass = 'text-danger'; }
    else if ($performance_score < 70) { $scoreColorClass = 'text-warning'; }

    $totalHotspotImpact = array_reduce($hotspots, fn($sum, $item) => $sum + ($item['impact'] ?? 0), 0);
    $hotspotsHtml = $totalHotspotImpact > 1e-9 ? implode('', array_map(function($item) use ($totalHotspotImpact) {
        $contributionPct = (($item['impact'] ?? 0) / $totalHotspotImpact) * 100;
        $itemName = htmlspecialchars($item['name']);
        return "<div><div class='d-flex justify-content-between small'><span>{$itemName}</span><span class='fw-bold'>" . number_format($contributionPct, 1) . "%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-secondary' style='width: {$contributionPct}%;'></div></div></div>";
    }, $hotspots)) : '<div class="text-muted small">無顯著熱點。</div>';

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $hotspotName = !empty($hotspots) ? htmlspecialchars($hotspots[0]['name']) : '';
    $hotspotRate = !empty($hotspots) ? ($hotspots[0]['rate'] ?? 0) * 100 : 0; // 取得損耗率並轉為 %
    $hotspotRateFormatted = number_format($hotspotRate, 1);
    $insight = '';

    if ($performance_score < 40 && $hotspotName) {
        if (count($hotspots) === 1) {
            $insight = "<strong>策略警示：</strong>此單一材料「{$hotspotName}」的製程損耗率經計算高達 <strong class=\"text-danger\">{$hotspotRateFormatted}%</strong>，這直接導致了產品整體的高生產廢棄物。建議立即檢討此材料的供應商製程或尋求替代品。";
        } else {
            $insight = "<strong>策略警示：</strong>產品的生產廢棄物過高。問題主要集中在「{$hotspotName}」，其製程損耗率高達 <strong class=\"text-danger\">{$hotspotRateFormatted}%</strong>，是您應優先處理的效率瓶頸。";
        }
    } elseif ($hotspotName) {
        if (count($hotspots) === 1) {
            $insight = "<strong>策略定位：</strong>產品的生產廢棄物完全來自「{$hotspotName}」，其製程損耗率為 <strong>{$hotspotRateFormatted}%</strong>。雖然目前表現尚可，但任何對此材料的效率提升，都將直接反映在總體績效上。";
        } else {
            $insight = "<strong>策略定位：</strong>主要的改善機會點在於「{$hotspotName}」，其製程損耗率為 <strong>{$hotspotRateFormatted}%</strong>。相較於其他組件，它是最具優化潛力的目標。";
        }
    } else {
        $insight = "<strong>策略總評：</strong>產品在生產廢棄物管理上表現優異，所有組件的材料利用率均在良好水平。";
    }

    $sdg_html = generateSdgIconsHtml([11, 12]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center"><i class="fas fa-dumpster text-primary me-2"></i>生產廢棄物計分卡<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>
            {$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="waste-scorecard" title="這代表什麼？"></i>
        </div>
        <div class="card-body d-flex flex-column">
            <div class="row g-4">
                <div class="col-lg-4 text-center border-end">
                    <h6 class="text-muted">改善分數</h6>
                    <div class="display-4 fw-bold {$scoreColorClass}">{$performance_score}</div>
                </div>
                <div class="col-lg-8">
                    <div class="p-2 bg-light-subtle rounded-3 mb-3">
                        <div class="row text-center gx-1">
                            <div class="col-6 border-end"><small class="text-muted">原生料基準 (kg)</small><p class="fw-bold mb-0">{$virgin_impact_kg}</p></div>
                            <div class="col-6"><small class="text-muted">當前設計 (kg)</small><p class="fw-bold mb-0 {$scoreColorClass}">{$total_impact_kg}</p></div>
                        </div>
                    </div>
                    <h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要貢獻來源 (Top 3)</h6>
                    <div class="d-flex flex-column gap-2 mt-2">{$hotspotsHtml}</div>
                </div>
            </div>
            <hr class="my-3">
            <div class="p-3 bg-light-subtle rounded-3 mt-auto">
                <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                <p class="small text-muted mb-0">{$insight}</p>
            </div>
        </div>
    </div>
HTML;
}

/**
 * 【v7.6 風險排序修正版】計算產品的自然相關風險與機會 (TNFD)
 */
function calculate_tnfd_analysis(array $components, array $materials_map): array
{
    global $tnfd_hotspot_dictionary, $ecosystem_dependency_dictionary, $impact_driver_dictionary;
    $total_weight = 0;
    $current_weighted_risk_sum = 0;
    $virgin_weighted_risk_sum = 0;
    $weighted_opp_sum = 0;
    $has_risk_mitigation_from_recycling = false;
    $weighted_dependency_sum = 0;

    $all_risks = [];
    $all_opportunities = [];
    $country_details = [];
    $all_dependencies = [];
    $all_impact_drivers = [];
    $risk_contributors = [];
    $hotspot_db_path = __DIR__ . '/biodiversity_risks_expert_zh_v2.json';
    $biodiversity_hotspots_db = file_exists($hotspot_db_path) ? json_decode(file_get_contents($hotspot_db_path), true) : [];

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        if (empty($key) || $weight <= 0) continue;

        $material = $materials_map[$key] ?? null;
        if (!$material) continue;

        $total_weight += $weight;
        $recycled_ratio = (float)($c['percentage'] ?? 0) / 100;
        $risk_multiplier = 1 - ($recycled_ratio * 0.95);
        if ($recycled_ratio > 0 && $risk_multiplier < 1) {
            $has_risk_mitigation_from_recycling = true;
        }

        $dependency_map = ['極高' => 100, '高' => 75, '中' => 50, '低' => 25];
        $dependency_level = $material['water_dependency_level'] ?? '中';
        $dependency_score = $dependency_map[$dependency_level] ?? 50;
        $weighted_dependency_sum += $dependency_score * $weight;

        $dependency_keys = json_decode($material['ecosystem_service_dependencies'] ?? '[]', true);
        if (is_array($dependency_keys)) {
            foreach($dependency_keys as $dep_key) {
                if(isset($ecosystem_dependency_dictionary[$dep_key])) {
                    $all_dependencies[] = $ecosystem_dependency_dictionary[$dep_key];
                }
            }
        }

        $driver_keys = json_decode($material['impact_drivers'] ?? '[]', true);
        if (is_array($driver_keys)) {
            foreach($driver_keys as $driver_key) {
                if(isset($impact_driver_dictionary[$driver_key])) {
                    $all_impact_drivers[] = $impact_driver_dictionary[$driver_key];
                }
            }
        }

        $origins = json_decode($material['country_of_origin'], true);
        if (!is_array($origins)) continue;

        foreach ($origins as $origin) {
            $country_en = $origin['country'] ?? '';
            $percentage = (float)($origin['percentage'] ?? 100) / 100;
            $material_weight_in_country = $weight * $percentage;

            if (isset($biodiversity_hotspots_db[$country_en])) {
                $country_data = $biodiversity_hotspots_db[$country_en];
                $base_country_risk_score = $country_data['risk_score'] ?? 50;
                $current_country_risk_score = $base_country_risk_score * $risk_multiplier;
                $current_weighted_risk_sum += $current_country_risk_score * $material_weight_in_country;
                $virgin_weighted_risk_sum += $base_country_risk_score * $material_weight_in_country;
                $weighted_opp_sum += ($country_data['opportunity_score'] ?? 50) * $material_weight_in_country;
                if(isset($country_data['risks'])) $all_risks = array_merge($all_risks, $country_data['risks']);
                if(isset($country_data['opportunities'])) $all_opportunities = array_merge($all_opportunities, $country_data['opportunities']);
                if (!isset($country_details[$country_en])) {
                    $country_details[$country_en] = $country_data;
                    $country_details[$country_en]['materials'] = [];
                    $country_details[$country_en]['sub_regions'] = [];
                }
                $country_details[$country_en]['materials'][] = $material['name'];

                if(isset($origin['tnfd_hotspot_regions'])) {
                    foreach($origin['tnfd_hotspot_regions'] as $region_key) {
                        if(isset($tnfd_hotspot_dictionary[$region_key])) {
                            $country_details[$country_en]['sub_regions'][] = $tnfd_hotspot_dictionary[$region_key];
                        }
                    }
                }

                $risk_contribution_value = $current_country_risk_score * $material_weight_in_country;
                if ($risk_contribution_value > 0) {
                    $risk_contributors[] = [
                        'material_name' => $material['name'],
                        'country_name_zh' => $country_data['countryNameZh'] ?? $country_en,
                        'contribution' => $risk_contribution_value
                    ];
                }
            }
        }
    }

    if ($total_weight <= 0) return ['success' => false];

    $overall_current_risk_score = $current_weighted_risk_sum / $total_weight;
    $overall_virgin_risk_score = $virgin_weighted_risk_sum / $total_weight;
    $overall_opportunity_score = $weighted_opp_sum / $total_weight;
    $overall_dependency_score = $weighted_dependency_sum / $total_weight;

    usort($risk_contributors, fn($a, $b) => $b['contribution'] <=> $a['contribution']);
    $top_contributors = array_slice($risk_contributors, 0, 3);
    if ($current_weighted_risk_sum > 0) {
        foreach($top_contributors as &$contributor) {
            $contributor['percentage'] = ($contributor['contribution'] / $current_weighted_risk_sum) * 100;
        }
    }
    unset($contributor);

    // ▼▼▼【核心修正】修正風險排序與去重的邏輯 ▼▼▼
    // 1. 先去除重複的風險項目，確保每個風險只出現一次
    $unique_risks = array_unique($all_risks, SORT_REGULAR);
    // 2. 然後才對這些獨一無二的風險項目，依照分數由高到低進行排序
    usort($unique_risks, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

    // 對機會也做同樣的處理，確保邏輯一致
    $unique_opportunities = array_unique($all_opportunities, SORT_REGULAR);
    usort($unique_opportunities, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    // ▲▲▲ 修正結束 ▲▲▲

    return [
        'success' => true,
        'overall_risk_score' => round($overall_current_risk_score, 1),
        'overall_virgin_risk_score' => round($overall_virgin_risk_score, 1),
        'overall_opportunity_score' => round($overall_opportunity_score, 1),
        'water_dependency_score' => round($overall_dependency_score, 1),
        'has_risk_mitigation_from_recycling' => $has_risk_mitigation_from_recycling,
        // 【核心修正】從排序好的 unique 陣列中取出前五名
        'top_risks' => array_slice($unique_risks, 0, 5),
        'top_opportunities' => array_slice($unique_opportunities, 0, 5),
        'hotspot_country_details' => $country_details,
        'dependencies' => array_values(array_unique($all_dependencies)),
        'impact_drivers' => array_values(array_unique($all_impact_drivers)),
        'risk_contributors' => $top_contributors
    ];
}

/**
 * 【V3.0 全新】計算與自然相關的潛在財務曝險價值 (VaR)
 */
function calculate_financial_risk_at_risk(array $components, array $materials_map, array $tnfd_data): array
{
    $total_material_cost = 0;
    $value_at_risk = 0;

    $hotspot_db_path = __DIR__ . '/biodiversity_risks_expert_zh_v2.json';
    $biodiversity_hotspots_db = file_exists($hotspot_db_path) ? json_decode(file_get_contents($hotspot_db_path), true) : [];

    foreach ($components as $c) {
        $key = $c['materialKey'] ?? '';
        $weight = (float)($c['weight'] ?? 0);
        $cost_per_kg = (float)($c['cost'] ?? $materials_map[$key]['cost_per_kg'] ?? 0);
        if (empty($key) || $weight <= 0) continue;

        $material_cost = $weight * $cost_per_kg;
        $total_material_cost += $material_cost;

        $material = $materials_map[$key] ?? null;
        if (!$material || empty($material['country_of_origin'])) continue;

        $origins = json_decode($material['country_of_origin'], true);
        if (!is_array($origins)) continue;

        foreach ($origins as $origin) {
            $country_en = $origin['country'] ?? '';
            $percentage = (float)($origin['percentage'] ?? 100) / 100;
            $cost_in_country = $material_cost * $percentage;

            $multiplier = $biodiversity_hotspots_db[$country_en]['nature_risk_cost_multiplier'] ?? 1.0;
            if ($multiplier > 1.0) {
                $value_at_risk += $cost_in_country * ($multiplier - 1);
            }
        }
    }

    $var_percentage = ($total_material_cost > 0) ? ($value_at_risk / $total_material_cost * 100) : 0;

    return [
        'total_material_cost' => round($total_material_cost, 2),
        'value_at_risk' => round($value_at_risk, 2),
        'var_percentage' => round($var_percentage, 1)
    ];
}

/**
 * 【V2.0 專家升級版】計算產品的進階循環經濟分數 (MCI)
 * @description 整合材料本身的「可回收率」，計算出更真實的「循環設計潛力」。
 * @param array $lca_result - 來自 calculate_lca_from_bom 的完整計算結果
 * @param array $materials_map - 包含所有物料詳細數據的查找表
 * @return array - 包含 MCI 分數、評級與各項循環指標的陣列
 */
function calculate_circularity_score(array $lca_result, array $materials_map): array
{
    $components = $lca_result['inputs']['components'] ?? [];
    $total_weight = $lca_result['inputs']['totalWeight'] ?? 0;
    if ($total_weight <= 0) {
        return ['success' => false, 'error' => '總重量為零，無法計算循環指數。'];
    }

    // --- 1. 計算 MCI (邏輯不變) ---
    $recycled_input_weight = $lca_result['charts']['content_by_type']['recycled'] ?? 0;
    $virgin_input_weight = $total_weight - $recycled_input_weight;
    $eol_recycle_pct = ($lca_result['inputs']['eol_scenario']['recycle'] ?? 0) / 100;
    $unrecoverable_waste_weight = $total_weight * (1 - $eol_recycle_pct);
    $lfi = ($virgin_input_weight + $unrecoverable_waste_weight) / (2 * $total_weight);
    $mci_score = max(0, min(1, 1 - $lfi));

    // --- 2. 【核心升級】計算「循環設計潛力」分數 ---
    $weighted_recyclability_sum = 0;
    foreach ($components as $component) {
        $material = $materials_map[$component['materialKey']] ?? null;
        if ($material) {
            $recyclability_rate = (float)($material['recyclability_rate_pct'] ?? 0);
            $weight = (float)($component['weight'] ?? 0);
            $weighted_recyclability_sum += $recyclability_rate * $weight;
        }
    }
    $design_for_recycling_score = ($total_weight > 0) ? ($weighted_recyclability_sum / $total_weight) : 0;


    // --- 3. 產生評級 (邏輯不變) ---
    $rating_text = '線性產品';
    $rating_color = 'danger';
    if ($mci_score >= 0.6) {
        $rating_text = '循環經濟典範';
        $rating_color = 'success';
    } elseif ($mci_score >= 0.3) {
        $rating_text = '轉型潛力股';
        $rating_color = 'info';
    }

    return [
        'success' => true,
        'mci_score' => round($mci_score * 100, 1),
        'design_for_recycling_score' => round($design_for_recycling_score, 1), // 【新增】回傳新分數
        'rating' => ['text' => $rating_text, 'color' => $rating_color],
        'breakdown' => [
            'recycled_content_pct' => ($total_weight > 0) ? ($recycled_input_weight / $total_weight * 100) : 0,
            'recycling_rate_pct' => $eol_recycle_pct * 100,
            'linear_flow_loss_pct' => ($total_weight > 0) ? (($virgin_input_weight + $unrecoverable_waste_weight) / (2 * $total_weight) * 100) : 100,
        ]
    ];
}

/**
 * 【V5.2 專家重構版 - 污染與水資源擴充】計算產品的環境績效細分總分
 * @description 核心擴充：1. 將「生態毒性」納入污染防治。 2. 建立更全面的「水資源管理」綜合分數。
 * @param array $lca_result 來自 calculate_lca_from_bom 的結果
 * @param array $circularity_result 來自 calculate_circularity_score 的結果
 * @param array $biodiversity_result 來自 calculate_biodiversity_impact 的結果
 * @param array $water_scarcity_result 來自 calculate_water_scarcity_impact 的結果
 * @param array $resource_depletion_result 來自 calculate_resource_depletion_impact 的結果
 * @return array 包含各構面分數與新 E 分數的陣列
 */
function calculate_environmental_performance(array $lca_result, array $circularity_result, array $biodiversity_result, array $water_scarcity_result, array $resource_depletion_result): array
{
    $imp = $lca_result['impact'];
    $v_imp = $lca_result['virgin_impact'];

    $calculate_improvement_score = function($current, $virgin) {
        if (abs($virgin) < 1e-9) { return ($current <= 0) ? 100 : 0; }
        $score = (($virgin - $current) / abs($virgin)) * 100;
        return max(0, min(100, $score));
    };

    // 1. 氣候行動 (不變)
    $co2_use_phase = $imp['co2_use'] ?? 0;
    // 從總碳排中減去使用階段的碳排，只比較「生產製造相關」的碳排
    $co2_prod_current = $imp['co2'] - $co2_use_phase;
    $co2_prod_virgin = $v_imp['co2'] - $co2_use_phase;

    $climate_score = $calculate_improvement_score($co2_prod_current, $co2_prod_virgin);

    // 2. 循環經濟 (不變)
    $mci_score = $circularity_result['mci_score'] ?? 0;
    $waste_score = $calculate_improvement_score($imp['waste'], $v_imp['waste']);
    $adp_score = $resource_depletion_result['performance_score'] ?? 0;
    $circularity_score = ($mci_score * 0.5) + ($waste_score * 0.25) + ($adp_score * 0.25);

    // 3. 水資源管理 (Water Stewardship) - 權重 20% (核心修改)
    // ▼▼▼ 核心修改開始 ▼▼▼
    $water_scarcity_score = $water_scarcity_result['performance_score'] ?? 0; // 水稀缺性 (AWARE)
    $water_consumption_score = $calculate_improvement_score($imp['water'], $v_imp['water']); // 總用水量
    $water_withdrawal_score = $calculate_improvement_score($imp['water_withdrawal'], $v_imp['water_withdrawal']); // 總取水量
    $wastewater_score = $calculate_improvement_score($imp['wastewater'], $v_imp['wastewater']); // 總廢水排放
    // 建立一個加權的綜合分數，AWARE 佔比較高
    $water_stewardship_score = ($water_scarcity_score * 0.4) + ($water_consumption_score * 0.2) + ($water_withdrawal_score * 0.2) + ($wastewater_score * 0.2);
    // ▲▲▲ 核心修改結束 ▲▲▲

    // 4. 污染防治 (Pollution Prevention) - 權重 15% (已擴充)
    $non_ghg_air_score = $calculate_improvement_score($imp['non_ghg_air'], $v_imp['non_ghg_air']);
    $soil_pollutants_score = $calculate_improvement_score($imp['soil_pollutants'], $v_imp['soil_pollutants']);
    $eutrophication_score = $calculate_improvement_score($imp['eutrophication'], $v_imp['eutrophication']);
    $acidification_score = $calculate_improvement_score($imp['acidification'], $v_imp['acidification']);
    $ecotox_score = $calculate_improvement_score($biodiversity_result['total_ecotox_ctue'] ?? 0, $biodiversity_result['virgin_ecotox_ctue'] ?? 0);
    $pollution_score = ($non_ghg_air_score + $soil_pollutants_score + $eutrophication_score + $acidification_score + $ecotox_score) / 5;

    // 5. 自然資本 (Natural Capital) - 權重 15% (不變)
    $natural_capital_score = $biodiversity_result['performance_score'] ?? 0;

    // 計算最終加權 E 分數
    $final_e_score = ($climate_score * 0.25) + ($circularity_score * 0.25) + ($water_stewardship_score * 0.20) + ($pollution_score * 0.15) + ($natural_capital_score * 0.15);

    return [
        'overall_e_score' => round($final_e_score, 1),
        'breakdown' => [
            'climate' => round($climate_score, 1),
            'circularity' => round($circularity_score, 1),
            'water' => round($water_stewardship_score, 1),
            'pollution' => round($pollution_score, 1),
            'nature' => round($natural_capital_score, 1),
        ],
        'sub_scores_for_debug' => [
            'waste_score' => round($waste_score, 1),
            'pollution_sub_scores' => [
                '非溫室氣體空氣污染' => round($non_ghg_air_score, 1),
                '土壤污染' => round($soil_pollutants_score, 1),
                '優養化' => round($eutrophication_score, 1),
                '酸化' => round($acidification_score, 1),
                '生態毒性' => round($ecotox_score, 1)
            ],
            // ▼▼▼ 核心修改：新增水資源子分數，供新儀表板使用 ▼▼▼
            'water_sub_scores' => [
                '水資源稀缺性 (AWARE)' => round($water_scarcity_score, 1),
                '總用水量' => round($water_consumption_score, 1),
                '總取水量' => round($water_withdrawal_score, 1),
                '總廢水排放' => round($wastewater_score, 1)
            ]
            // ▲▲▲ 核心修改結束 ▲▲▲
        ]
    ];
}

/**
 * 【V9.8 Badge 修正版】為桑基圖儀表板標題補上 ESG 範疇標籤。
 */
function generate_sankey_deep_dive_html(): string
{
    $sdg_html = generateSdgIconsHtml([9, 12, 16]);
    return <<<HTML
    <div class="card h-100 shadow-sm sankey-analyzer-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-sitemap text-primary me-2"></i>多維度桑基圖分析儀
                <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>
            </h5>{$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="sankey-analyzer-dashboard" title="這代表什麼？" style="cursor: pointer; opacity: 0.8;"></i>
        </div>
        <div class="p-0 border-bottom" style="background-color: var(--bs-tertiary-bg);">
             <ul class="nav nav-tabs nav-tabs-bordered nav-fill" id="sankeyTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="mass-flow-tab" data-bs-toggle="tab" data-bs-target="#mass-flow-pane" type="button" role="tab">
                        <i class="fas fa-recycle me-2"></i>物質流與循環性
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="carbon-flow-tab" data-bs-toggle="tab" data-bs-target="#carbon-flow-pane" type="button" role="tab">
                        <i class="fas fa-smog me-2"></i>氣候衝擊分析
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="cost-flow-tab" data-bs-toggle="tab" data-bs-target="#cost-flow-pane" type="button" role="tab">
                        <i class="fas fa-chart-line me-2"></i>財務績效衝擊
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="risk-flow-tab" data-bs-toggle="tab" data-bs-target="#risk-flow-pane" type="button" role="tab">
                        <i class="fas fa-shield-alt me-2"></i>供應鏈韌性診斷
                    </button>
                </li>
                 <li class="nav-item" role="presentation">
                    <button class="nav-link" id="water-flow-tab" data-bs-toggle="tab" data-bs-target="#water-flow-pane" type="button" role="tab">
                        <i class="fas fa-tint me-2"></i>水資源管理視角
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <div class="sankey-kpi-bar p-3 border-bottom"></div>
            <div class="sankey-content-wrapper">
                <div class="sankey-chart-main position-relative">
                    <div class="tab-content h-100" id="sankeyTabContent">
                        <div class="tab-pane fade show active h-100 p-3" id="mass-flow-pane"><canvas id="sankeyChartMass"></canvas></div>
                        <div class="tab-pane fade h-100 p-3" id="carbon-flow-pane"><canvas id="sankeyChartCarbon"></canvas></div>
                        <div class="tab-pane fade h-100 p-3" id="cost-flow-pane"><canvas id="sankeyChartCost"></canvas></div>
                        <div class="tab-pane fade h-100 p-3" id="risk-flow-pane"><canvas id="sankeyChartRisk"></canvas></div>
                        <div class="tab-pane fade h-100 p-3" id="water-flow-pane"><canvas id="sankeyChartWater"></canvas></div>
                    </div>
                    <button class="btn btn-primary btn-sm" id="sankey-show-detail-btn" style="display:none;"><i class="fas fa-lightbulb me-2"></i>顯示洞察</button>
                </div>
                <div class="sankey-detail-panel shadow-lg">
                    <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-light-subtle">
                        <h6 class="mb-0" id="sankey-detail-title">詳細資訊</h6>
                        <button type="button" class="btn-close" id="sankey-detail-close-btn" title="收合面板"></button>
                    </div>
                    <div class="p-3" id="sankey-detail-content"></div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-light-subtle">
            <h6 class="small fw-bold"><i class="fas fa-sliders-h text-primary me-2"></i>即時動態模擬器</h6>
            <label for="sankey-simulator-slider" class="form-label small mb-1">全局再生料比例: <span id="sankey-simulator-value" class="fw-bold text-primary">--%</span></label>
            <input type="range" class="form-range" id="sankey-simulator-slider" min="0" max="100" step="1">
        </div>
    </div>
HTML;
}

/**
 * 【V8.1 專家UI升級版 - 整合儀表板】產生全新的供應鏈 S&G 風險儀表板 HTML
 * @description 將原本的風險熱點與風險矩陣整合成一個有敘事流程的診斷儀表板。
 */
function generate_comprehensive_sg_dashboard_html(): string
{
    $sdg_html = generateSdgIconsHtml([8, 10, 16]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-microscope text-primary me-2"></i>供應鏈 S&G 風險儀表板
                <span class="badge bg-warning-subtle text-warning-emphasis ms-2">供應鏈 (S+G)</span>
            </h5>{$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="comprehensive-sg-dashboard" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-4 border-end">
                    <div class="d-flex flex-column h-100">
                        <h6 class="text-muted">核心風險指標 (0-100, 越高越差)</h6>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="p-2 bg-light-subtle rounded-3 text-center">
                                    <h6 class="small text-muted mb-0">社會(S)風險</h6>
                                    <p class="fs-2 fw-bold text-warning mb-0" id="sg-summary-s-score">--</p>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-light-subtle rounded-3 text-center">
                                    <h6 class="small text-muted mb-0">治理(G)風險</h6>
                                    <p class="fs-2 fw-bold text-secondary mb-0" id="sg-summary-g-score">--</p>
                                </div>
                            </div>
                        </div>
                        
                        <h6 class="text-muted mt-2">Top 3 風險貢獻來源</h6>
                        <div id="sg-hotspot-list-container" class="d-flex flex-column gap-2 mb-3">
                            <p class="small text-muted">計算中...</p>
                        </div>

                        <div class="p-3 bg-light-subtle rounded-3 mt-auto">
                             <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                             <p id="sg-comprehensive-narrative" class="small text-muted mb-0">圖表生成後將顯示策略建議。</p>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                     <h6 class="text-muted text-center">供應鏈 S&G 風險矩陣</h6>
                     <div style="height: 400px; position: relative;"><canvas id="sgRiskMatrixChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>
HTML;
}

/**
 * 【v7.4 介面統一版】產生「總體水足跡子卡」的 HTML
 */
function generate_total_water_footprint_card_html(array $lca_data): string
{
    $impact = $lca_data['impact'] ?? [];
    $virgin_impact = $lca_data['virgin_impact'] ?? [];

    $render_metric = function($label, $unit, $current_val_raw, $virgin_val_raw, $fixed = 2) {
        $current_val = number_format((float)$current_val_raw, $fixed);
        $virgin_val = number_format((float)$virgin_val_raw, $fixed);
        $diff = $virgin_val_raw - $current_val_raw;
        $pct_change_text = '';
        if (abs($virgin_val_raw) > 1e-9) {
            $pct = ($diff / abs($virgin_val_raw)) * 100;
            $color = $diff >= 0 ? 'success' : 'danger';
            $arrow = $diff >= 0 ? 'fa-arrow-down' : 'fa-arrow-up';
            $pct_change_text = "<span class=\"badge bg-{$color}-subtle text-{$color}-emphasis border border-{$color}-subtle\"><i class=\"fas {$arrow} me-1\"></i>" . number_format($pct, 1) . "%</span>";
        }

        return <<<HTML
        <div class="col-md-4">
            <div class="p-2 bg-light-subtle rounded-3 text-center h-100">
                <h6 class="small text-muted d-flex justify-content-center align-items-center">{$label}</h6>
                <p class="fs-4 fw-bold mb-1">{$current_val}</p>
                <div class="d-flex justify-content-between align-items-center px-2">
                    <small class="text-muted">vs. {$virgin_val} {$unit}</small>
                    {$pct_change_text}
                </div>
            </div>
        </div>
HTML;
    };

    $consumption_imp = ($virgin_impact['water'] > 0) ? (($virgin_impact['water'] - $impact['water']) / $virgin_impact['water']) : 0;
    $withdrawal_imp = ($virgin_impact['water_withdrawal'] > 0) ? (($virgin_impact['water_withdrawal'] - $impact['water_withdrawal']) / $virgin_impact['water_withdrawal']) : 0;
    $wastewater_imp = ($virgin_impact['wastewater'] > 0) ? (($virgin_impact['wastewater'] - $impact['wastewater']) / $virgin_impact['wastewater']) : 0;

    $improvements = ['用水量' => $consumption_imp, '取水量' => $withdrawal_imp, '廢水排放' => $wastewater_imp];
    asort($improvements);
    $weakest_link = key($improvements);

    $insight = "數據顯示，主要的改善機會點在於<strong>「{$weakest_link}」</strong>的管理。";
    if ($improvements[$weakest_link] < 0) {
        $insight = "<strong>策略警示：</strong>產品在<strong>「{$weakest_link}」</strong>方面的表現劣於原生料基準，這是一個需要優先處理的<strong class=\"text-danger\">環境權衡風險</strong>。";
    }

    $sdg_html = generateSdgIconsHtml([6]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-faucet text-primary me-2"></i>總體水足跡子卡
                <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>
            </h5>{$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="total-water-footprint" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
            <div class="row g-2">
                {$render_metric('總用水量', 'L', $impact['water'], $virgin_impact['water'], 1)}
                {$render_metric('總取水量', 'm³', $impact['water_withdrawal'], $virgin_impact['water_withdrawal'], 3)}
                {$render_metric('總廢水排放', 'm³', $impact['wastewater'], $virgin_impact['wastewater'], 3)}
            </div>
            <hr class="my-3">
            <div class="p-3 bg-light-subtle rounded-3">
                <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                <p class="small text-muted mb-0">{$insight}</p>
            </div>
        </div>
    </div>
HTML;
}

/**
 * 【V3.3 最終版】產生環境績效總覽儀表板的 HTML
 * @description 整合了頂部KPI指標區塊與水平五欄式構面分析，為最終版面。
 * @param array $lca_data - 來自 calculate_lca_from_bom 的完整結果
 * @param array $env_perf_data - 來自 calculate_environmental_performance 的結果
 * @return string - 完整的儀表板 HTML
 */
function generate_environmental_performance_overview_html(array $lca_data, array $env_perf_data): string
{
    if (empty($lca_data) || empty($env_perf_data)) {
        return '';
    }

    $overall_e_score = $env_perf_data['overall_e_score'] ?? 0;
    $breakdown = $env_perf_data['breakdown'] ?? [];
    $impact = $lca_data['impact'] ?? [];
    $virgin_impact = $lca_data['virgin_impact'] ?? [];

    $format_saved_text = function($current, $virgin, $unit) {
        if (abs($virgin) < 1e-9) return '<small class="text-muted">基準為0</small>';
        $diff = $virgin - $current;
        $pct = ($diff / abs($virgin)) * 100;
        $color = $diff >= 0 ? 'success' : 'danger';
        $arrow = $diff >= 0 ? 'fa-arrow-down' : 'fa-arrow-up';
        return "<small class=\"text-{$color}\"><i class=\"fas {$arrow}\"></i> " . number_format($pct, 1) . "%</small>";
    };

    $getScoreColor = function ($score) {
        if ($score >= 75) return 'success';
        if ($score >= 50) return 'info';
        if ($score >= 25) return 'warning';
        return 'danger';
    };

    $renderSubScore = function ($label, $score, $icon, $tooltip) use ($getScoreColor) {
        $color = $getScoreColor($score);
        return <<<HTML
        <div class="col">
            <div class="text-center p-2 rounded-3 bg-light-subtle h-100">
                <h6 class="small text-muted d-flex align-items-center justify-content-center">{$label} <i class="fas fa-question-circle ms-2" data-bs-toggle="tooltip" title="{$tooltip}"></i></h6>
                <p class="fw-bold fs-4 mb-1 text-{$color}"><i class="fas {$icon} me-2"></i>{$score}</p>
                <div class="progress" style="height: 6px;">
                    <div class="progress-bar bg-{$color}" style="width: {$score}%;"></div>
                </div>
            </div>
        </div>
HTML;
    };

    $insight = '';
    if (!empty($breakdown)) {
        $sortedBreakdown = $breakdown;
        asort($sortedBreakdown);
        $weakest_key = key($sortedBreakdown);
        $weakest_score = current($sortedBreakdown);
        arsort($sortedBreakdown);
        $strongest_key = key($sortedBreakdown);
        $strongest_score = current($sortedBreakdown);
        $sub_scores_map = ['climate'=>'氣候行動', 'circularity'=>'循環經濟', 'water'=>'水資源管理', 'pollution'=>'污染防治', 'nature'=>'自然資本'];

        if ($weakest_score < 40) {
            $insight = "<strong>策略警示：</strong>產品在「<strong class=\"text-danger\">" . ($sub_scores_map[$weakest_key] ?? $weakest_key) . "</strong>」構面表現最為薄弱 (分數: {$weakest_score})，是您提升整體環境績效時，應最優先處理的短版。";
        } else {
            $insight = "<strong>策略總評：</strong>產品在五大環境構面表現均衡，其中以「<strong class=\"text-success\">" . ($sub_scores_map[$strongest_key] ?? $strongest_key) . "</strong>」(分數: {$strongest_score}) 最為突出。這是一個穩健的設計，可將優勢項目作為您的永續溝通亮點。";
        }
    }

    $sdg_html = generateSdgIconsHtml([6, 7, 12, 13, 14, 15]);
    $overall_color = $getScoreColor($overall_e_score);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-leaf text-primary me-2"></i>環境績效總覽<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>{$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="environmental-performance-overview" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
            <div class="row text-center mb-4">
                <div class="col-md-3 border-end">
                    <h6 class="text-muted small">綜合環境分數</h6>
                    <div class="display-4 fw-bold text-{$overall_color}">{$overall_e_score}</div>
                </div>
                <div class="col-md-3 border-end">
                    <h6 class="text-muted small">總碳足跡 (kg CO₂e)</h6>
                    <div class="fs-4 fw-bold">{$impact['co2']}</div>
                    {$format_saved_text($impact['co2'], $virgin_impact['co2'], 'kg CO₂e')}
                </div>
                <div class="col-md-3 border-end">
                    <h6 class="text-muted small">總能源消耗 (MJ)</h6>
                    <div class="fs-4 fw-bold">{$impact['energy']}</div>
                    {$format_saved_text($impact['energy'], $virgin_impact['energy'], 'MJ')}
                </div>
                <div class="col-md-3">
                    <h6 class="text-muted small">總水資源消耗 (L)</h6>
                    <div class="fs-4 fw-bold">{$impact['water']}</div>
                    {$format_saved_text($impact['water'], $virgin_impact['water'], 'L')}
                </div>
            </div>
            <hr>
            <h6 class="mb-3">五大構面分析</h6>
            <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3">
                {$renderSubScore('氣候行動', $breakdown['climate'] ?? 0, 'fa-smog', '減碳成效')}
                {$renderSubScore('循環經濟', $breakdown['circularity'] ?? 0, 'fa-recycle', '資源利用與廢棄物管理')}
                {$renderSubScore('水資源管理', $breakdown['water'] ?? 0, 'fa-tint', '水資源消耗與稀缺性衝擊')}
                {$renderSubScore('污染防治', $breakdown['pollution'] ?? 0, 'fa-biohazard', '各類污染物排放控制')}
                {$renderSubScore('自然資本', $breakdown['nature'] ?? 0, 'fa-paw', '生物多樣性與土地利用衝擊')}
            </div>

            <hr>
            <div class="p-3 bg-light-subtle rounded-3">
                <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                <p class="small text-muted mb-0">{$insight}</p>
            </div>
        </div>
    </div>
HTML;
}

/**
 * 【V6.2 細項分數強化版】計算產品的綜合 ESG 分數
 * @description 核心擴充：將 S 與 G 的細項分數也傳遞出去，供儀表板顯示。
 */
function calculate_esg_score(array $environmental_performance, array $social_result, array $governance_result, array $biodiversity_result, array $water_scarcity_result, array $resource_depletion_result): array
{
    // E 分數
    $e_performance_score = $environmental_performance['overall_e_score'];
    $e_sub_scores = $environmental_performance['breakdown'] ?? [];

    // S 分數
    $s_risk_score = $social_result['overall_risk_score'] ?? 50;
    $s_performance_score = max(0, min(100, 100 - $s_risk_score));
    $s_sub_scores = $social_result['sub_scores'] ?? [];

    // G 分數
    $g_risk_score = $governance_result['overall_risk_score'] ?? 30;
    $g_performance_score = max(0, min(100, 100 - $g_risk_score));
    $g_sub_scores = $governance_result['sub_scores'] ?? [];

    // 綜合 ESG 分數
    $combined_esg_score = ($e_performance_score + $s_performance_score + $g_performance_score) / 3;

    return [
        'e_score' => round($e_performance_score, 1),
        's_score' => round($s_performance_score, 1),
        'g_score' => round($g_performance_score, 1),
        'combined_score' => round($combined_esg_score, 1),
        'e_sub_scores' => $e_sub_scores,
        's_sub_scores' => $s_sub_scores,
        'g_sub_scores' => $g_sub_scores
    ];
}

/**
 * 【V6.0 全新】整合計算 S&G 風險熱點
 * @description 整合社會與治理風險貢獻，找出對綜合聲譽風險影響最大的物料。
 * @param array $social_result 來自 calculate_social_impact 的結果
 * @param array $governance_result 來自 calculate_governance_impact 的結果
 * @return array 排序後的 S&G 熱點物料列表
 */
function calculate_sg_hotspots(array $social_result, array $governance_result): array
{
    $combined_risks = [];
    $total_s_risk = 0;
    $total_g_risk = 0;

    if (isset($social_result['risk_contribution'])) {
        foreach ($social_result['risk_contribution'] as $item) {
            $total_s_risk += $item['weighted_risk'];
            $combined_risks[$item['name']]['s_risk'] = $item['weighted_risk'];
            $combined_risks[$item['name']]['name'] = $item['name'];
        }
    }

    if (isset($governance_result['risk_contribution'])) {
        foreach ($governance_result['risk_contribution'] as $item) {
            $total_g_risk += $item['weighted_risk'];
            $combined_risks[$item['name']]['g_risk'] = $item['weighted_risk'];
            $combined_risks[$item['name']]['name'] = $item['name'];
        }
    }

    $hotspots = [];
    $total_combined_risk = $total_s_risk + $total_g_risk;

    if ($total_combined_risk > 0) {
        foreach ($combined_risks as $name => $risks) {
            $s_risk = $risks['s_risk'] ?? 0;
            $g_risk = $risks['g_risk'] ?? 0;
            $total_risk = $s_risk + $g_risk;
            $hotspots[] = [
                'name' => $name,
                's_risk_pct' => ($total_combined_risk > 0) ? ($s_risk / $total_combined_risk * 100) : 0,
                'g_risk_pct' => ($total_combined_risk > 0) ? ($g_risk / $total_combined_risk * 100) : 0,
                'total_risk_pct' => ($total_combined_risk > 0) ? ($total_risk / $total_combined_risk * 100) : 0,
            ];
        }
    }

    usort($hotspots, fn($a, $b) => $b['total_risk_pct'] <=> $a['total_risk_pct']);

    return array_slice($hotspots, 0, 7); // 回傳貢獻最大的前 7 名
}

/**
 * 【V2.0 全新】AI 推薦溝通 SDG 引擎
 * @description 根據產品的各項永續績效，自動推薦最適合溝通的 SDGs，並識別潛在的漂綠風險。
 * @param array $data 完整的 perUnitData 物件
 * @return array 包含推薦的 SDGs 列表或風險警示的陣列
 */
function identifyRelevantSDGs(array $data): array
{
    $archetype = $data['story_score']['weaknesses'] ?? [];
    if (in_array('「偽生態設計」警示：高再生料投入反而導致碳排顯著增加，故事存在嚴重矛盾。', $archetype)) {
        return ['risk' => '高漂綠風險', 'message' => '偵測到「偽生態設計」特徵（高循環投入但碳排惡化）。在解決此核心矛盾前，不建議進行任何與SDG相關的溝通。'];
    }

    $sdgs = [];
    $e_scores = $data['environmental_performance']['breakdown'] ?? [];
    $s_score = $data['esg_scores']['s_score'] ?? 0;
    $g_score = $data['esg_scores']['g_score'] ?? 0;
    $recycled_pct = $data['circularity_analysis']['breakdown']['recycled_content_pct'] ?? 0;

    // 根據環境績效五大構面推薦
    if (($e_scores['climate'] ?? 0) >= 75) $sdgs[13] = ['number' => 13, 'reason' => '在「氣候行動」上表現卓越，減碳成效顯著。'];
    if (($e_scores['circularity'] ?? 0) >= 75 || $recycled_pct >= 70) $sdgs[12] = ['number' => 12, 'reason' => '在「循環經濟」實踐上表現傑出，符合責任消費與生產模式。'];
    if (($e_scores['water'] ?? 0) >= 75) $sdgs[6] = ['number' => 6, 'reason' => '在「水資源管理」上表現優異，對潔淨水資源衝擊低。'];
    if (($e_scores['nature'] ?? 0) >= 75) {
        $sdgs[14] = ['number' => 14, 'reason' => '在「自然資本」保護上表現突出，有助於維護海洋與陸域生態。'];
        $sdgs[15] = ['number' => 15, 'reason' => '在「自然資本」保護上表現突出，有助於維護海洋與陸域生態。'];
    }
    if (($e_scores['pollution'] ?? 0) >= 75) $sdgs[11] = ['number' => 11, 'reason' => '在「污染防治」上表現良好，有助於建立永續城市與社區。'];

    // 根據 S, G 表現推薦
    if ($s_score >= 75) $sdgs[8] = ['number' => 8, 'reason' => '供應鏈社會(S)風險低，符合「尊嚴就業」的核心精神。'];
    if ($g_score >= 75) $sdgs[16] = ['number' => 16, 'reason' => '供應鏈治理(G)風險低，體現了「和平、正義與健全制度」的企業責任。'];

    if(empty($sdgs)){
        return ['risk' => '溝通機會點不足', 'message' => '目前產品的各項永續績效尚未達到足以作為關鍵溝通亮點的水準，建議優先聚焦於產品優化而非大規模溝通。'];
    }

    return ['recommendations' => array_values($sdgs)];
}

/**
 * 【V1.1 - 修正版】計算產品的「永續故事力™」評分
 * @param array $lca_result 完整的LCA計算結果
 * @param array $social_result 完整的社會衝擊計算結果
 * @return array 包含分數、評級、優缺點分析的陣列
 */
function calculate_storytelling_score(array $lca_result, array $social_result): array
{
    $score = 50.0; // 基礎分數
    $strengths = [];
    $weaknesses = [];

    // 從 LCA 結果中提取所需數據
    $imp = $lca_result['impact'];
    $v_imp = $lca_result['virgin_impact'];
    $co2_val = $imp['co2'];
    $co2_reduction_pct = $v_imp['co2'] > 0.001 ? (($v_imp['co2'] - $co2_val) / $v_imp['co2'] * 100) : ($co2_val < 0 ? 100 : 0);
    $recycled_pct = ($lca_result['inputs']['totalWeight'] > 0) ? ($lca_result['charts']['content_by_type']['recycled'] / $lca_result['inputs']['totalWeight'] * 100) : 0;

    // 1. 核心英雄指標：減碳成效 (權重: 40%)
    if ($co2_val < -0.001) {
        $score += 25; // 碳負排放是超級英雄，直接給予最高獎勵
        $strengths[] = "達成「碳負排放」，故事的絕對主角！";
    } else {
        $score += $co2_reduction_pct * 0.4;
        if ($co2_reduction_pct > 70) $strengths[] = "頂級的減碳表現 (>". round($co2_reduction_pct) ."%)，氣候故事強而有力。";
        elseif ($co2_reduction_pct > 40) $strengths[] = "顯著的減碳成果 (>". round($co2_reduction_pct) ."%)，具備優秀的市場溝通潛力。";
    }

    // 2. 循環經濟敘事 (權重: 20%)
    $score += $recycled_pct * 0.2;
    if ($recycled_pct > 70) $strengths[] = "極高的再生料佔比 (>". round($recycled_pct) ."%)，是循環經濟的典範。";
    elseif ($recycled_pct > 40) $strengths[] = "高比例的再生材料 (>". round($recycled_pct) ."%)，有效傳達了循環承諾。";

    // 3. 社會責任基石 (權重: 20%)
    $social_score = $social_result['overall_risk_score'] ?? 50;
    $score += (100 - $social_score) * 0.1; // 社會風險越低，得分越高
    if ($social_score < 30) $strengths[] = "極低的供應鏈社會風險，強化了品牌的可信度。";
    if ($social_score > 70) {
        $score -= 15; // 高社會風險會嚴重扣分
        $weaknesses[] = "供應鏈存在高社會風險，可能削弱永續故事的說服力。";
    }

    // 4. 故事一致性檢查 (扣分項)
    $has_trade_off = false;
    foreach($lca_result['environmental_fingerprint_scores'] as $key => $s) {
        if ($key !== 'co2' && $s < -10) {
            $has_trade_off = true;
            break;
        }
    }

    if ($recycled_pct > 40 && $co2_reduction_pct < -10) {
        $score -= 30; // 偽生態設計，重扣
        $weaknesses[] = "「偽生態設計」警示：高再生料投入反而導致碳排顯著增加，故事存在嚴重矛盾。";
    }
    if ($has_trade_off) {
        $score -= 10; // 衝擊轉移，扣分
        $weaknesses[] = "「衝擊轉移」現象：減碳的同時，在其他環境指標上表現惡化，故事不夠完整。";
    }

    // 標準化分數至 0-100 之間
    $final_score = max(0, min(100, round($score)));

    // 評級
    $rating = 'D';
    if ($final_score >= 95) $rating = 'S+';
    elseif ($final_score >= 90) $rating = 'S';
    elseif ($final_score >= 80) $rating = 'A';
    elseif ($final_score >= 70) $rating = 'B';
    elseif ($final_score >= 60) $rating = 'C';

    return [
        'score' => $final_score,
        'rating' => $rating,
        'strengths' => $strengths,
        'weaknesses' => $weaknesses
    ];
}

/**
 * 【V6.4 策略四象限版 - 單一組件邏輯強化版】在伺服器端產生綜合分析數據
 */
function generate_holistic_analysis_php(array $data): array
{
    // --- 1. 核心指標計算 (為「永續定位」分析保留，邏輯不變) ---
    $imp = $data['impact'] ?? ['co2' => 0, 'energy' => 0, 'water' => 0];
    $v_imp = $data['virgin_impact'] ?? ['co2' => 0, 'energy' => 0, 'water' => 0];
    $co2_val = $imp['co2'];

    $co2_reduction_pct = ($v_imp['co2'] > 0.001) ? (($v_imp['co2'] - $co2_val) / $v_imp['co2'] * 100) : (($co2_val < 0) ? 100 : 0);
    $energy_reduction_pct = ($v_imp['energy'] > 0) ? (($v_imp['energy'] - $imp['energy']) / $v_imp['energy'] * 100) : 0;
    $water_reduction_pct = ($v_imp['water'] > 0) ? (($v_imp['water'] - $imp['water']) / $v_imp['water'] * 100) : 0;

    $recycled_weight = $data['charts']['content_by_type']['recycled'] ?? 0;
    $total_weight = $data['inputs']['totalWeight'] ?? 0;
    $recycled_pct = ($total_weight > 0) ? ($recycled_weight / $total_weight * 100) : 0;
    $material_efficiency_score = ($recycled_pct > 1) ? min(100, max(0, ($co2_reduction_pct / $recycled_pct) * 50 + 50)) : 50;

    // --- 2. 整合五大構面分數為四大策略支柱 (邏輯不變) ---
    $e_scores_breakdown = $data['environmental_performance']['breakdown'] ?? [];
    $adp_score = $data['resource_depletion_impact']['performance_score'] ?? 0;

    $resource_stewardship_score = (($e_scores_breakdown['water'] ?? 0) + $adp_score) / 2;
    $impact_mitigation_score = (($e_scores_breakdown['pollution'] ?? 0) + ($e_scores_breakdown['nature'] ?? 0)) / 2;

    $radar_scores = [
        round($e_scores_breakdown['climate'] ?? 0, 1),
        round($e_scores_breakdown['circularity'] ?? 0, 1),
        round($resource_stewardship_score, 1),
        round($impact_mitigation_score, 1)
    ];

    // --- 3. 呼叫分析函式取得 profile (邏輯不變) ---
    $metrics = [ 'co2_val' => $co2_val, 'co2_reduction_pct' => $co2_reduction_pct, 'energy_reduction_pct' => $energy_reduction_pct, 'water_reduction_pct' => $water_reduction_pct, 'recycled_pct' => $recycled_pct, 'material_efficiency_score' => $material_efficiency_score ];
    $profile = generateProfileAnalysis_php($metrics);

    // --- 4. 【核心升級】關鍵熱點分析與建議，現在能區分單一/多組件情境 ---
    $hotspot_name = find_environmental_hotspot($data['charts']['composition'] ?? []);
    $composition = $data['charts']['composition'] ?? [];
    $hotspot_component = null;
    foreach($composition as $c) { if ($c['name'] === $hotspot_name) { $hotspot_component = $c; break; } }

    $advice_html = '';
    if ($hotspot_component) {
        $is_single_component = count($composition) === 1;
        $hotspot_display_name = $is_single_component ? "此單一組件「" . htmlspecialchars($hotspot_name) . "」" : "最主要環境熱點「" . htmlspecialchars($hotspot_name) . "」";

        if (($hotspot_component['percentage'] ?? 0) < 100) {
            $advice_html = "<li class=\"list-group-item p-2\"><strong>首要策略 - 提升再生比例：</strong>針對{$hotspot_display_name}，最直接、最高效益的改善行動是提升其再生材料的使用比例。建議立即與供應商接洽，評估將其再生料比例提升至 80% 以上的可行性與成本效益。</li>";
        } else {
            $advice_html = "<li class=\"list-group-item p-2\"><strong>首要策略 - 尋求創新替代：</strong>{$hotspot_display_name}已採用高比例再生料或無再生料選項。下一步的策略核心應是探索「創新材料替代」或進行「輕量化設計」，以從根本上降低此熱點的衝擊。</li>";
        }
    } else {
        $advice_html = "<li class=\"list-group-item p-2\"><strong>首要策略：</strong>請優先處理已識別出的環境熱點「" . htmlspecialchars($hotspot_name) . "」，透過提升再生比例或尋找低碳替代方案來進行優化。</li>";
    }

    // --- 5. 回傳最終數據 ---
    return [
        'profile' => $profile,
        'radar_data' => $radar_scores,
        'hotspot_name' => $hotspot_name,
        'advice_html' => $advice_html // 將生成好的建議 HTML 直接傳給前端
    ];
}

/**
 * 【V6.1 水依賴整合版】產生「綜合水資源管理計分卡」的 HTML
 * @description AI 洞察現在會結合「水依賴度」與「水稀缺性(AWARE)」進行雙維度風險評估。
 */
function generate_water_management_scorecard_html(array $lca_data, array $env_perf_data, array $water_scarcity_data, array $tnfd_data): string
{
    $water_score = $env_perf_data['breakdown']['water'] ?? 0;
    $sub_scores_data = $env_perf_data['sub_scores_for_debug']['water_sub_scores'] ?? [];
    $impact = $lca_data['impact'] ?? [];
    $virgin_impact = $lca_data['virgin_impact'] ?? [];
    $water_dependency_score = $tnfd_data['water_dependency_score'] ?? 50; // <-- 【新增】取得水依賴分數
    $water_scarcity_score = $water_scarcity_data['performance_score'] ?? 100;

    $score_color = $water_score >= 75 ? 'success' : ($water_score >= 50 ? 'primary' : 'warning');

    $metrics = [
        'water_scarcity' => ['label' => '水資源稀缺性 (AWARE)', 'virgin' => $water_scarcity_data['virgin_impact_m3_world_eq'] ?? 0, 'current' => $water_scarcity_data['total_impact_m3_world_eq'] ?? 0, 'score' => $sub_scores_data['水資源稀缺性 (AWARE)'] ?? 0, 'unit' => 'm³ eq.', 'fixed' => 3 ],
        'consumption' => ['label' => '總用水量', 'virgin' => $virgin_impact['water'] ?? 0, 'current' => $impact['water'] ?? 0, 'score' => $sub_scores_data['總用水量'] ?? 0, 'unit' => 'L', 'fixed' => 1],
        'withdrawal' => ['label' => '總取水量', 'virgin' => $virgin_impact['water_withdrawal'] ?? 0, 'current' => $impact['water_withdrawal'] ?? 0, 'score' => $sub_scores_data['總取水量'] ?? 0, 'unit' => 'm³', 'fixed' => 3],
        'wastewater' => ['label' => '總廢水排放', 'virgin' => $virgin_impact['wastewater'] ?? 0, 'current' => $impact['wastewater'] ?? 0, 'score' => $sub_scores_data['總廢水排放'] ?? 0, 'unit' => 'm³', 'fixed' => 3]
    ];

    $metrics_html = '';
    foreach ($metrics as $key => $m) {
        $current_color = ($m['current'] <= $m['virgin']) ? 'text-success' : 'text-danger';
        $score_color_badge = $m['score'] >= 75 ? 'success' : ($m['score'] >= 50 ? 'info' : ($m['score'] > 0 ? 'warning' : 'danger'));
        $metrics_html .= "<div class='col-12 col-md-6 mb-3'><div class='p-3 bg-light-subtle rounded-3 h-100'><div class='d-flex justify-content-between align-items-center'><h6 class='mb-0 small fw-bold'>{$m['label']}</h6><span class='badge bg-{$score_color_badge}-subtle text-{$score_color_badge}-emphasis border border-{$score_color_badge}-subtle'>{$m['score']} / 100</span></div><hr class='my-2'><div class='d-flex justify-content-between small'><span class='text-muted'>原生料基準:</span><span>".number_format($m['virgin'], $m['fixed'])." <small>{$m['unit']}</small></span></div><div class='d-flex justify-content-between small'><span class='text-muted'>當前設計:</span><span class='fw-bold {$current_color}'>".number_format($m['current'], $m['fixed'])." <small>{$m['unit']}</small></span></div></div></div>";
    }

    // --- 【核心升級】AI 智慧洞察，進行雙維度風險評估 ---
    $insight = '數據不足，無法提供具體建議。';
    if ($water_dependency_score >= 75 && $water_scarcity_score < 40) {
        $insight = "<strong>策略警示：雙重水風險暴露。</strong>您的產品不僅高度依賴水資源進行生產 (依賴度: {$water_dependency_score})，同時其水足跡也主要分佈在<strong class='text-danger'>水資源高度稀缺的地區</strong> (衝擊分: {$water_scarcity_score})。這是最危險的組合，可能面臨嚴重的供應鏈中斷風險。";
    } elseif ($water_dependency_score >= 75) {
        $insight = "<strong>策略定位：高營運依賴風險。</strong>您的產品生產過程高度依賴水資源 (依賴度: {$water_dependency_score})。儘管目前對稀缺地區的衝擊不大，但未來任何形式的水資源短缺都可能直接影響您的產能與成本。";
    } elseif ($water_scarcity_score < 40) {
        $insight = "<strong>策略定位：高地理位置風險。</strong>您的產品供應鏈坐落在水資源稀缺地區 (衝擊分: {$water_scarcity_score})，雖然產品本身對水的依賴度不高，但仍需警惕地方性的水資源政策與社區衝突風險。";
    } else {
        $insight = "<strong>策略總評：水資源管理穩健。</strong>您的產品在「營運依賴度」與「地理稀缺性」兩個維度上均表現出低風險特徵，具備良好的水資源韌性。";
    }

    $sdg_html = generateSdgIconsHtml([6, 14]);
    return <<<HTML
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="fas fa-hand-holding-water text-primary me-2"></i>綜合水資源管理計分卡<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>{$sdg_html}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="water-management-overview" title="這代表什麼？"></i></div>
        <div class="card-body"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">水資源管理總分</h6><div class="display-3 fw-bold text-{$score_color}">{$water_score}</div><p class="small text-muted mt-2">(0-100, 越高越好)</p><hr>
                    <div class="p-2 bg-light-subtle rounded-3"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div></div>
                <div class="col-lg-8"><h6 class="text-muted">四大構面分數計算明細</h6><div class="row">{$metrics_html}</div></div></div>
             <hr class="my-3"><div class="row g-4"><div class="col-12"><h6 class="text-muted">四大構面改善分數 (視覺化圖表)</h6><div style="height: 180px;"><canvas id="waterBreakdownChart"></canvas></div></div></div></div></div>
HTML;
}

// index.php

/**
 * 【V2.0 供應來源國整合版】產生水資源短缺足跡(AWARE)子卡的 HTML
 */
function generate_water_scarcity_scorecard_html(array $waterData): string {
    if (!isset($waterData['success']) || !$waterData['success']) {
        return '';
    }

    $performance_score = $waterData['performance_score'] ?? 0;
    $total_impact_m3_world_eq = $waterData['total_impact_m3_world_eq'] ?? 0;
    $virgin_impact_m3_world_eq = $waterData['virgin_impact_m3_world_eq'] ?? 0;
    $hotspots = $waterData['hotspots'] ?? [];

    $scoreColorClass = 'text-success';
    if ($performance_score < 40) { $scoreColorClass = 'text-danger'; }
    else if ($performance_score < 70) { $scoreColorClass = 'text-warning'; }

    $totalHotspotImpact = array_reduce($hotspots, fn($sum, $item) => $sum + ($item['impact'] ?? 0), 0);
    $hotspotsHtml = '';
    if ($totalHotspotImpact > 1e-9) {
        foreach($hotspots as $item) {
            $contributionPct = (($item['impact'] ?? 0) / $totalHotspotImpact) * 100;
            $itemName = htmlspecialchars($item['name']);
            $hotspotsHtml .= "<div><div class='d-flex justify-content-between small'><span>{$itemName}</span><span class='fw-bold'>" . number_format($contributionPct, 1) . "%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-info' style='width: {$contributionPct}%;'></div></div></div>";
        }
    } else {
        $hotspotsHtml = '<div class="text-muted small">無顯著熱點。</div>';
    }

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $hotspot = !empty($hotspots) ? $hotspots[0] : null;
    $insight = '';
    if ($performance_score < 40 && $hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $country = htmlspecialchars($hotspot['source_country']);
        $insight = "<strong>策略警示：</strong>產品對水資源稀缺地區構成<strong class=\"text-danger\">高衝擊風險</strong>。主要壓力源來自於「{$hotspotName}」，其供應鏈溯源至<strong class=\"text-danger\">水資源極度緊張的「{$country}」</strong>，這可能構成嚴重的供應鏈脆弱性。";
    } elseif ($hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $insight = "<strong>策略定位：</strong>產品的水風險在可控範圍內。主要的改善機會點在於優化「{$hotspotName}」的水稀缺足跡，可評估更換其供應來源地的可能性。";
    } else {
        $insight = "<strong>策略總評：</strong>產品在水資源短缺足跡上表現優異，未發現顯著的單一衝擊熱點。";
    }

    $sdg_html = generateSdgIconsHtml([6]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center"><i class="fas fa-hand-holding-water text-primary me-2"></i>水資源短缺足跡(AWARE)子卡<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>
            {$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="water-scarcity-scorecard" title="這代表什麼？"></i>
        </div>
        <div class="card-body d-flex flex-column"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">改善分數</h6><div class="display-4 fw-bold {$scoreColorClass}">{$performance_score}</div></div>
                <div class="col-lg-8">
                    <div class="p-2 bg-light-subtle rounded-3 mb-3">
                        <div class="row text-center gx-1">
                            <div class="col-6 border-end"><small class="text-muted">原生料基準</small><p class="fw-bold mb-0">{$virgin_impact_m3_world_eq}<small> m³ eq.</small></p></div>
                            <div class="col-6"><small class="text-muted">當前設計</small><p class="fw-bold mb-0 {$scoreColorClass}">{$total_impact_m3_world_eq}<small> m³ eq.</small></p></div>
                        </div>
                    </div>
                    <h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要貢獻來源 (Top 3)</h6><div class="d-flex flex-column gap-2 mt-2">{$hotspotsHtml}</div>
                </div></div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3 mt-auto"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div></div></div>
HTML;
}

/**
 * 【V9.11 Bug 修正版】AI 指令庫：為「溝通文案產生器」打造專用 Prompt
 * @description 1. 修正了讀取 storyArchetype 時，鍵值 'name' 應為 'title' 的錯誤。
 * @description 2. 修正了當 totalWeight 為零時，會導致「除以零」致命錯誤的 bug。
 */
function get_ai_prompt_for_comms(array $reportData, array $storyArchetype): string
{
    $total_weight = $reportData['inputs']['totalWeight'] ?? 0;
    $recycled_weight = $reportData['charts']['content_by_type']['recycled'] ?? 0;
    $recycled_content_pct = ($total_weight > 0) ? ($recycled_weight / $total_weight * 100) : 0;

    // ▼▼▼ 【核心修正】將讀取 'name' 改為讀取 'title' ▼▼▼
    $archetype_name = $storyArchetype['title'] ?? '智者';
    // ▲▲▲ 修正完畢 ▲▲▲

    $key_metrics = [
        'product_name' => $reportData['versionName'] ?? '此產品',
        'story_archetype' => $archetype_name,
        'co2_reduction_pct' => $reportData['environmental_fingerprint_scores']['co2'] ?? 0,
        'recycled_content_pct' => $recycled_content_pct,
        's_risk_score' => $reportData['social_impact']['overall_risk_score'] ?? 50,
        'equivalents' => [
            'car_km' => $reportData['equivalents']['car_km'] ?? 0,
            'showers' => $reportData['equivalents']['showers'] ?? 0,
        ],
        'verifiable_claims' => [
            'carbon_claim' => "碳足跡較產業基準降低 " . number_format($reportData['environmental_fingerprint_scores']['co2'], 1) . "%",
            'recycled_claim' => "採用 " . number_format($recycled_content_pct, 1) . "% 再生材料",
        ]
    ];
    $json_data = json_encode($key_metrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return "
    角色：你是一位頂級的永續品牌溝通策略師，同時也是一位文案寫作大師。
    任務：根據下方提供的產品數據摘要，以及為其診斷出的「故事原型」，為我生成一份包含多種格式的整合溝通材料。

    產品數據摘要:
    ```json
    {$json_data}
    ```

    寫作要求:
    1.  **緊扣故事原型：** 所有的文案風格與切入點，都必須與提供的「故事原型」({$archetype_name}) 緊密結合。
    2.  **數據驅動：** 文案中必須巧妙地融入數據摘要中的至少兩項量化數據，使其具備說服力。
    3.  **多格式輸出：** 你的回覆必須嚴格遵循下面的 Markdown 格式，為三種不同情境產生內容。
    4.  **語言：** 使用繁體中文。

    ---
    
    ### 📰 新聞稿亮點 (Press Release Highlights)
    * **標題：** (請產生一個引人注目的新聞標題)
    * **第一段 (導言)：** (約 50 字，快速總結產品最大的永續成就)
    * **數據支持：** (用條列式，列出 2-3 個最關鍵的數據證據)
    
    ### 📱 社群媒體貼文 (Social Media Post for B2C)
    * **平台：** Instagram / Facebook
    * **文案：** (約 80-100 字，語氣活潑、情感豐富，能引發消費者共鳴。記得使用 emoji 和 hashtags)
    
    ### 📈 B2B 銷售亮點 (B2B Sales Talking Points)
    * **目標對象：** 企業採購方、供應鏈夥伴
    * **要點：** (請用 3-4 個條列式重點，說明此產品的永續性如何為 B2B 客戶帶來商業價值，例如：降低其 Scope 3 排放、確保供應鏈合規性、提升其品牌形象等)
    ";
}

/**
 * 【V5.0 - 人格化格式版】AI 指令庫 (Prompt Library)
 * @description 為每一個人格設定了獨一無二的 Markdown 格式化要求，讓格式成為個性的一部分。
 * @param string $persona_key - 人格的關鍵字 (e.g., 'consultant', 'marketer')
 * @return string - 完整的 Prompt 字串
 */
function get_ai_prompt_for_persona(string $persona_key): string
{
    switch ($persona_key) {
        case 'marketer':
            return "
            角色：你是一位頂尖的品牌行銷總監，對永續議題有深刻理解，極度擅長發掘產品的綠色亮點，並將其轉化為能打動人心的品牌故事與社群文案。
            任務：根據下方 JSON 格式的數據，撰寫一段約 250 字，充滿熱情與說服力的產品行銷文案。
            寫作框架與要求：
            1.  標題 (The Hook): 用一句話，創造一個引人入勝、彰顯產品價值的行銷口號。
            2.  亮點聚焦 (The Spotlight): **明確總結產品在『碳足跡減量』上的核心成就**，並找出數據中最值得稱讚的 1-2 個優勢（如：驚人的減碳比例、綠色折扣），用充滿感染力的語言加以放大。
            3.  價值連結 (The Value Proposition): 將這些技術上的成就，與消費者的核心價值（如：愛護地球、精明消費、支持創新）連結起來。
            4.  社群發文建議 (Social Media Snippets): 【全新要求】**提供 2 句可以直接複製用於社群媒體 (如 Facebook, Instagram) 的貼文建議句**，風格要活潑、引人注目，可包含 Hashtags。
            5.  行動呼籲 (The Call to Action): 用一句話鼓勵消費者選擇這款更永續、更具前瞻性的產品。
            6.  語氣：積極、樂觀、充滿希望與啟發性。
            7.  格式化要求：請使用 GitHub Flavored Markdown 增強視覺效果。你可以自由運用段落、**重點強調**，並用 `*` 或 `-` 來列出產品亮點，讓文案更具吸引力。標題請直接加粗即可，無需使用 '#'
            8.  語言: 使用繁體中文。
            ";
        case 'analyst':
            return "
            角色：你是一位資深LCA數據分析師，為一家嚴謹的第三方認證機構工作。你的任務是提供一份完全基於數據、客觀中立的分析摘要。
            任務：根據下方 JSON 格式的數據，撰寫一段約 250 字，不帶任何情感色彩的分析報告。
            寫作框架與要求：
            1.  總體概述 (Overall Finding): 客觀陳述產品的ESG總分與其所屬的永續定位。
            2.  量化績效 (Quantitative Performance): 依序、條列式地報告產品在關鍵指標上的表現。
            3.  財務影響 (Financial Implication): 客觀報告此設計所帶來的綠色溢價或綠色折扣的具體數值。
            4.  風險揭露 (Risk Disclosure): 條列出數據中所揭示的主要風險。
            5.  語氣：中立、客觀、精確、數據驅動。避免使用比喻或任何帶有主觀判斷的形容詞。
            6.  格式化要求：你的回應必須嚴格遵循 GitHub Flavored Markdown 格式。使用 `###` 作為每個區塊的標題。所有量化績效與風險揭露，都必須使用 `*` 項目符號清單條列出來，確保清晰與精確。
            7.  語言: 使用繁體中文。
            ";
        case 'storyteller':
            return "
            角色：你是一位永續品牌故事創作大師，擅長將冷冰冰的數據轉化為引人入勝的敘事，讓觀眾在情感上與產品建立連結。
            任務：根據下方 JSON 格式的數據，撰寫一篇約 250 字的永續品牌故事。
            寫作框架與要求：
            1.  開場畫面 (Opening Scene): 用生動的描述，帶讀者進入一個能體現產品永續精神的場景。
            2.  衝突與轉折 (Conflict & Turning Point): 描述在永續之路上遇到的挑戰，以及產品如何突破。
            3.  高潮 (Climax): 展現數據中的亮眼成就，讓讀者感到驚喜與鼓舞。
            4.  收尾呼籲 (Closing Appeal): 讓讀者產生行動慾望，參與這段永續旅程。
            5.  語氣：溫暖、具感染力、富有畫面感。
            6.  格式化要求：使用段落分隔，關鍵詞加粗，適度使用 `*` 突出情感詞。
            7.  語言: 使用繁體中文。
            ";
        case 'engineer':
            return "
            角色：你是一位永續產品研發工程師，專注於材料、製程與設計優化，並以精準的技術語言傳達成就。
            任務：根據下方 JSON 格式的數據，撰寫一篇約 250 字的技術成果報告。
            寫作框架與要求：
            1.  技術背景 (Technical Context): 說明產品所採用的核心技術與設計思路。
            2.  數據成果 (Measured Results): 條列化呈現數據中最關鍵的效益與改善幅度。
            3.  工程意義 (Engineering Significance): 分析這些成果對於產品性能、壽命、成本或永續性的意涵。
            4.  後續優化方向 (Next Steps): **【全新要求】建議可再提升的技術面向，特別是針對數據中的環境熱點 (`hotspot_material`)**，提出具體的材料或製程優化建議。
            5.  語氣：專業、精確、專注於技術與數據。
            6.  格式化要求：使用 `###` 分段，`*` 條列化，數據必須附單位。
            7.  語言: 使用繁體中文。
            ";
        case 'educator':
            return "
            角色：你是一位永續教育講師，擅長將複雜的 ESG 數據轉化為簡單易懂的知識，讓大眾也能理解。
            任務：根據下方 JSON 格式的數據，撰寫一篇約 250 字的教育性解說。
            寫作框架與要求：
            1.  簡介 (Introduction): 用日常生活的例子引入主題。
            2.  核心數據解釋 (Key Data Explained): 將數據轉化為生活化的比較與比喻。
            3.  意義 (Why It Matters): 說明這些成果對社會、環境或個人行為的啟發。
            4.  行動啟發 (Inspire Action): 鼓勵讀者採取具體行動。
            5.  語氣：親切、啟發性、簡單易懂。
            6.  格式化要求：使用 `*` 或數字清單列出要點，關鍵詞加粗。
            7.  語言: 使用繁體中文。
            ";
        case 'journalist':
            return "
            角色：你是一位專攻永續議題的調查記者，擅長用犀利的文字揭露真相，並兼顧專業性與新聞價值。
            任務：根據下方 JSON 格式的數據，撰寫一篇約 250 字的新聞稿。
            寫作框架與要求：
            1.  標題 (Headline): 用一句有衝擊力的新聞標題吸引注意。
            2.  導言 (Lead): 簡明扼要地交代最重要的事實與背景。
            3.  核心數據 (Key Data): 條列化呈現數據，並指出變化趨勢。
            4.  引述 (Quote): 模擬引用專家或公司代表的評論，增強真實感。
            5.  語氣：客觀、具新聞價值，但可用詞鋒利以引發討論。
            6.  格式化要求：標題加粗，`###` 分段，數據用 `*` 條列。
            7.  語言: 使用繁體中文。
            ";
        case 'ai_innovator':
            return "
            角色：你是一位 AI 創新顧問，專精於運用人工智慧、大數據與自動化來驅動永續轉型，讓 ESG 成果加速倍增。
            任務：根據下方 JSON 格式的數據，撰寫一篇約 250 字的創新策略建議。
            寫作框架與要求：
            1.  科技亮點 (Tech Highlights): 指出數據中可用 AI/自動化強化的關鍵環節。
            2.  加速機制 (Acceleration Levers): 分析 AI 如何加快減碳、節能或成本優化。
            3.  商業價值 (Business Impact): 說明技術帶來的投資報酬與市場差異化優勢。
            4.  下一步 (Next Actions): 提出 2-3 個立即可行的 AI 導入行動。
            5.  語氣：前瞻、策略性、帶有科技領導感。
            6.  格式化要求：`###` 分段，`*` 條列化，關鍵詞加粗。
            7.  語言: 使用繁體中文。
            ";
        case 'crisis_pr':
            return "
            角色：你是一位危機公關專家，專門在 ESG 與永續數據出現負面或爭議時，為企業打造沉著、可信的對外說法。
            任務：根據下方 JSON 格式的數據，撰寫一篇約 250 字的危機回應聲明。
            寫作框架與要求：
            1.  立場表明 (Position Statement): 清楚陳述公司對數據結果的立場與責任態度。
            2.  關鍵解釋 (Key Explanation): 解釋造成不理想數據的原因（事實為主，避免推諉）。
            3.  改善承諾 (Commitments): 提出具體改善計畫與時間表。
            4.  信心重建 (Reassurance): 強調公司在永續目標上的堅持與透明度。
            5.  語氣：沉著、專業、誠懇，避免防禦性語言。
            6.  格式化要求：`###` 分段，重要承諾與數據用 **加粗**，可適度使用 `*` 列點。
            7.  語言: 使用繁體中文。
            ";
        case 'consultant':
        default:
            return "
            角色：你是一位風格犀利、極度務實的頂級永續策略顧問，被譽為「企業永續性的壓力測試專家」。你的風格如同電影中的「魔鬼教官」，從不說廢話，總是一針見血地指出最殘酷的真相與被忽略的風險。你為 CEO 和董事會服務，你的任務是戳破綠色泡沫，而不是製造它們。
            任務：根據下方 JSON 格式的數據，撰寫一段約 250 字的專業分析摘要。
            寫作框架與要求：
            1.  標題 (The Headline): 提出你對此產品最關鍵、最震撼的總結。
            2.  診斷 (The Diagnosis): 分析此產品在永續性上的策略定位，找出數據中的「矛盾」與「衝突」。
            3.  策略意涵 (The 'So What?'): 深入挖掘這些矛盾背後的商業風險。
            4.  行動路徑 (The Path Forward): 提出 2-3 個最優先、最高價值的行動建議。**【全新要求】必須明確針對數據中的『環境熱點』(`hotspot_material`) 提出具體改善建議**，例如：評估低碳替代材料、提升其再生比例、或進行輕量化設計。
            5.  語氣：直接、自信、甚至帶點挑釁。目標是警醒決策者，而不是取悅他們。
            6.  格式化要求：請使用 GitHub Flavored Markdown 進行格式化。各部分標題（診斷、策略意涵等）請使用 `###`。重點關鍵字請使用 `**` 加粗。行動路徑請使用 `1.`、`2.` 的有序清單。
            7.  善用比喻與類比：使用強而有力的比喻（如：阿基里斯之踵、特洛伊木馬）讓觀點更具穿透力。
            8.  語言: 使用繁體中文。
            ";
    }
}/**
 * 【V4.2 - 數據擴充版】呼叫 Gemini API
 * @description 擴充傳遞給 AI 的關鍵指標，包含所有新模組的數據，以產生更全面的洞察。
 * @param array $reportData 來自前端的完整 perUnitData 物件
 * @param string $persona 使用者選擇的人格 (e.g., 'consultant', 'marketer', 'analyst')
 * @return array 包含成功狀態與生成文字的陣列
 */
function generate_ai_narrative(array $reportData, string $persona): array
{
    // 1. 檢查 API 金鑰是否已設定
    if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY) || GEMINI_API_KEY === '在這裡貼上您從Google AI Studio複製的API金鑰') {
        return ['success' => false, 'message' => '尚未設定有效的 Gemini API 金鑰。'];
    }

    try {
        // 2. 【核心升級】從報告中提煉更全面的關鍵指標，作為給 AI 的「食材」
        $key_metrics = [
            'product_name' => $reportData['versionName'] ?? '此產品',
            'esg_profile' => $reportData['holistic_analysis']['profile']['title'] ?? 'N/A',
            'esg_score' => $reportData['esg_scores']['combined_score'] ?? 'N/A',
            'co2_reduction_pct' => ($reportData['virgin_impact']['co2'] > 0) ? (($reportData['virgin_impact']['co2'] - $reportData['impact']['co2']) / $reportData['virgin_impact']['co2'] * 100) : 0,
            'hotspot_material' => find_environmental_hotspot($reportData['charts']['composition'] ?? []),
            'financial' => (isset($reportData['commercial_benefits']) && $reportData['commercial_benefits']['success']) ? [
                'green_premium_or_discount' => $reportData['commercial_benefits']['green_premium_per_unit'],
                'gross_margin' => $reportData['commercial_benefits']['gross_margin'],
            ] : '無數據',
            'social_risk' => [
                'score' => $reportData['social_impact']['overall_risk_score'] ?? 'N/A',
                'top_risks' => array_slice($reportData['social_impact']['unique_risks'] ?? [], 0, 2),
            ],
            'governance_risk' => [
                'score' => $reportData['governance_impact']['overall_risk_score'] ?? 'N/A',
                'top_risks' => array_slice($reportData['governance_impact']['unique_risks'] ?? [], 0, 2),
            ],
            'circularity' => [
                'mci_score' => $reportData['circularity_analysis']['mci_score'] ?? 'N/A',
                'recycled_content_pct' => $reportData['circularity_analysis']['breakdown']['recycled_content_pct'] ?? 'N/A',
            ],
            'regulatory_risk' => [
                'total_cost_twd' => ($reportData['regulatory_impact']['cbam_cost_twd'] ?? 0) + ($reportData['regulatory_impact']['plastic_tax_twd'] ?? 0),
                'has_svhc' => !empty($reportData['regulatory_impact']['svhc_items']),
            ],
            // 【新增】納入生物多樣性、水、資源、TNFD 的摘要數據
            'biodiversity' => [
                'performance_score' => $reportData['biodiversity_impact']['performance_score'] ?? 'N/A',
                'top_risks' => array_slice($reportData['biodiversity_impact']['unique_risks'] ?? [], 0, 2)
            ],
            'water_scarcity' => [
                'performance_score' => $reportData['water_scarcity_impact']['performance_score'] ?? 'N/A',
                'top_hotspot_name' => $reportData['water_scarcity_impact']['hotspots'][0]['name'] ?? 'N/A'
            ],
            'resource_depletion' => [
                'performance_score' => $reportData['resource_depletion_impact']['performance_score'] ?? 'N/A',
                'top_hotspot_name' => $reportData['resource_depletion_impact']['hotspots'][0]['name'] ?? 'N/A'
            ],
            'tnfd_risk' => [
                'overall_risk_score' => $reportData['tnfd_analysis']['overall_risk_score'] ?? 'N/A',
                'value_at_risk_twd' => $reportData['tnfd_analysis_html'] ? ($reportData['tnfd_analysis']['financial_risk']['value_at_risk'] ?? 0) : 0, // 假設 financial_risk 嵌套在 tnfd_analysis 內
                'top_risk_text' => $reportData['tnfd_analysis']['top_risks'][0]['text'] ?? 'N/A'
            ]
        ];

        // 3. 呼叫指令庫，根據傳入的 persona 取得對應的 prompt
        $base_prompt = get_ai_prompt_for_persona($persona);
        $prompt = $base_prompt . "\n\n績效數據:\n" . json_encode($key_metrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n請開始你的報告：";

        // 4. 準備 API 請求的相關設定
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL_NAME . ':generateContent?key=' . GEMINI_API_KEY;
        $post_data = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.6,
                'topK' => 40,
                'topP' => 1,
                'maxOutputTokens' => 800,
            ]
        ];

        // 5. 使用 cURL 執行 API 請求 (此部分不變)
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // 6. 處理 API 回應與錯誤 (此部分不變)
        if ($curl_error) {
            return ['success' => false, 'message' => "cURL 請求到底層發生錯誤: " . $curl_error];
        }
        $result = json_decode($response, true);
        if ($http_code !== 200 || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $error_message = $result['error']['message'] ?? '未知的API錯誤，請檢查API金鑰是否有效或帳戶額度。';
            return ['success' => false, 'message' => "Gemini API 服務回傳錯誤 (HTTP Code: {$http_code}): " . $error_message];
        }

        // 7. 成功取得回應，回傳生成的文字
        $generated_text = $result['candidates'][0]['content']['parts'][0]['text'];
        return ['success' => true, 'narrative' => $generated_text];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'PHP 執行時發生例外錯誤: ' . $e->getMessage()];
    }
}/**
 * 【全新】呼叫 Gemini API 以生成後續對話
 */
function generate_ai_follow_up_response(array $reportData, string $persona, array $chatHistory, string $newQuestion): array
{
    if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY) || GEMINI_API_KEY === '在這裡貼上您從Google AI Studio複製的API金鑰') {
        return ['success' => false, 'message' => '尚未設定有效的 Gemini API 金鑰。'];
    }

    try {
        // --- 重新建構完整的上下文 ---
        $context_prompt = get_ai_prompt_for_persona($persona);
        $key_metrics = [ /* ... 此處省略了完整的數據提取，與 generate_ai_narrative 函式中的邏輯完全相同 ... */ ];

        $full_prompt = $context_prompt . "\n\n# 產品核心數據\n" . json_encode($key_metrics, JSON_UNESCAPED_UNICODE) . "\n\n";
        $full_prompt .= "# 已有對話紀錄\n";
        foreach ($chatHistory as $entry) {
            $full_prompt .= "{$entry['role']}: {$entry['content']}\n";
        }
        $full_prompt .= "\n# 使用者最新提問\n{$newQuestion}\n\n# 請根據以上所有上下文，回答使用者最新的提問：";

        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL_NAME . ':generateContent?key=' . GEMINI_API_KEY;
        $post_data = [
            'contents' => [['parts' => [['text' => $full_prompt]]]],
            'generationConfig' => [ 'temperature' => 0.7, 'maxOutputTokens' => 800 ] // 對話時可以稍微提高溫度
        ];

        // ... 此處省略了與 generate_ai_narrative 函式中完全相同的 cURL 請求與回應處理邏輯 ...
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_error($ch);
        curl_close($ch);
        $result = json_decode($response, true);
        if ($http_code !== 200 || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return ['success' => false, 'message' => "Gemini API 服務回傳錯誤 (HTTP Code: {$http_code})"];
        }
        return ['success' => true, 'narrative' => $result['candidates'][0]['content']['parts'][0]['text']];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'PHP 執行時發生例外錯誤: ' . $e->getMessage()];
    }
}

/**
 * 【V6.2 細項分數強化版】產生 ESG 綜合儀表板的 HTML
 * @description 核心擴充：在 S 和 G 的儀表下方，顯示其細項組成，提升透明度。
 */
function generate_esg_scorecard_html(array $esg_data): string
{
    $esg_score = $esg_data['combined_score'];
    $e_score = $esg_data['e_score'];
    $s_score = $esg_data['s_score'];
    $g_score = $esg_data['g_score'];

    $e_sub_scores = $esg_data['e_sub_scores'] ?? [];
    $s_sub_scores = $esg_data['s_sub_scores'] ?? [];
    $g_sub_scores = $esg_data['g_sub_scores'] ?? [];

    $rating = ['text' => '有待加強', 'color' => 'danger', 'grade' => 'C', 'desc' => '產品在ESG整體表現上存在顯著的改善空間，可能面臨市場或法規挑戰。'];
    if ($esg_score >= 85) { $rating = ['text' => '典範級', 'color' => 'success', 'grade' => 'AA', 'desc' => '在ESG各面向均展現出卓越的領導力，是行業的標竿，具備頂級的市場競爭力。']; }
    elseif ($esg_score >= 70) { $rating = ['text' => '領先級', 'color' => 'primary', 'grade' => 'A', 'desc' => '整體ESG表現優於多數同業，已建立穩固的永續基礎和顯著的綠色競爭力。']; }
    elseif ($esg_score >= 55) { $rating = ['text' => '穩健級', 'color' => 'info', 'grade' => 'B', 'desc' => 'ESG表現達到行業平均水平，風險可控，但需設定明確目標以追求更高層次的表現。']; }

    $get_light_color = function ($score) { if ($score >= 75) return '#198754'; if ($score >= 50) return '#ffc107'; return '#dc3545'; };
    $e_color = $get_light_color($e_score);
    $s_color = $get_light_color($s_score);
    $g_color = $get_light_color($g_score);
    $gauge_svg = function ($score, $color) { $radius = 45; $circumference = M_PI * $radius; $offset = $circumference * (1 - ($score / 100)); return <<<SVG
<svg viewBox="0 0 100 57" class="w-100"><path d="M 5,50 A {$radius},{$radius} 0 0 1 95,50" fill="none" stroke="#e9ecef" stroke-width="10" /><path d="M 5,50 A {$radius},{$radius} 0 0 1 95,50" fill="none" stroke="{$color}" stroke-width="10" stroke-linecap="round" stroke-dasharray="{$circumference}" stroke-dashoffset="{$offset}" style="transition: stroke-dashoffset 0.8s ease-in-out;"></path><text x="50" y="45" text-anchor="middle" font-size="22" font-weight="bold" fill="{$color}">{$score}</text></svg>
SVG; };

    $profile_details = [ 'title' => '綜合評估', 'icon' => 'fa-question-circle', 'color' => 'secondary', 'insight' => '產品呈現複合型的ESG表現，建議逐一檢視各構面分數以規劃後續行動。', 'advice' => '請深入檢視各面向的計分卡，找出造成分數偏低的具體原因，並以此為基礎規劃您的下一步優化行動。'];
    if ($e_score >= 75 && $s_score >= 75 && $g_score >= 75) { $profile_details = ['title' => '全方位領導者', 'icon' => 'fa-crown', 'color' => 'success', 'insight' => '您的產品在 E、S、G 三大構面均達到卓越水平，展現了系統性、高度整合的永續管理能力，是行業中的頂級標竿。', 'advice' => '策略重點應轉向「價值溝通」。將此全面的永續績效轉化為引人入勝的品牌故事，並將此設計方法論標準化，導入所有未來的產品開發流程。']; }
    elseif ($e_score >= 75 && $s_score < 50) { $profile_details = ['title' => '環境績效驅動者', 'icon' => 'fa-leaf', 'color' => 'primary', 'insight' => '您的產品在環境(E)構面表現傑出，但在社會(S)構面上存在顯著的短版。這揭示了潛在的「衝擊轉移」風險 — 即追求環境效益的同時，可能忽略了供應鏈的社會責任。', 'advice' => '建議立即檢視「社會責任計分卡」，找出造成分數偏低的熱點材料或供應來源國，並啟動供應商盡職調查，以建立一個更具韌性的永續策略。']; }
    elseif ($s_score >= 75 && $e_score < 50) { $profile_details = ['title' => '社會責任模範生', 'icon' => 'fa-users', 'color' => 'info', 'insight' => '您的產品在社會責任(S)上建立了卓越的標準，但在環境(E)績效上仍有巨大的提升潛力。這是一個常見的發展路徑，顯示公司優先關注了人權與勞工議題。', 'advice' => '下一步的策略核心是「將社會責任與環境效益連結」。建議利用「AI 產品最佳化引擎」，在維持低社會風險的前提下，尋找能最大化降低碳足跡的替代材料。']; }
    elseif ($g_score >= 75 && $e_score < 50 && $s_score < 50) { $profile_details = ['title' => '穩健治理者', 'icon' => 'fa-landmark', 'color' => 'secondary', 'insight' => '您的供應鏈在企業治理(G)上建立了穩固的基礎，但在環境(E)與社會(S)兩個實踐層面均存在較高的風險。這意味著您的管理體系可能更側重於合規與財務透明度。', 'advice' => '策略重點是將頂層的治理承諾，轉化為具體的E和S行動。建議優先從「環境熱點分析」著手，找出最容易改善、投資報酬率最高的優化目標。']; }
    elseif ($e_score < 50 && $s_score < 50 && $g_score < 50) { $profile_details = ['title' => '系統性風險暴露', 'icon' => 'fa-bomb', 'color' => 'danger', 'insight' => '警示：您的產品在 E、S、G 三大構面均呈現高風險狀態。此畫像代表了顯著的營運、法規與聲譽風險，可能導致供應鏈中斷或品牌價值受損。', 'advice' => '強烈建議進行一次根本性的「永續策略覆盤」。需要從最基礎的材料選擇、供應商盡職調查和產品生命週期思維上進行全面性的重新評估。']; }

    $detailed_insight_html = <<<HTML
<hr class="my-4"><div class="row"><div class="col-12"><h6 class="mb-3"><i class="fas fa-search-plus text-primary me-2"></i>AI 智慧洞察：<span class="badge fs-6 text-{$profile_details['color']} bg-{$profile_details['color']}-subtle border border-{$profile_details['color']}-subtle"><i class="fas {$profile_details['icon']} me-2"></i>{$profile_details['title']}</span></h6><p class="small mb-2"><strong><i class="fas fa-lightbulb text-info fa-fw me-2"></i>分析：</strong>{$profile_details['insight']}</p><p class="small mb-0"><strong><i class="fas fa-bullseye text-danger fa-fw me-2"></i>建議：</strong>{$profile_details['advice']}</p></div></div>
HTML;

    $generate_sub_scores_html = function($sub_scores, $is_risk = false) {
        if(empty($sub_scores)) return '';
        $html = '<div class="d-flex justify-content-around flex-wrap">';
        foreach($sub_scores as $label => $score) {
            $score = round($score, 0);
            $final_score = $is_risk ? (100 - $score) : $score; // 風險分數要反轉為績效分數
            $html .= "<span class='mx-2'>{$label}: <strong>{$score}</strong></span>";
        }
        $html .= '</div>';
        return $html;
    };

    $e_sub_scores_map = ['climate'=>'氣候', 'circularity'=>'循環', 'water'=>'水資源', 'pollution'=>'污染', 'nature'=>'自然'];
    $e_sub_scores_formatted = [];
    foreach($e_sub_scores as $key => $score) { $e_sub_scores_formatted[$e_sub_scores_map[$key] ?? $key] = $score; }

    // E 的分數是績效分(越高越好)，S/G 的細項分數是風險分(越低越好)
    $e_sub_scores_html = '<div class="text-center small text-muted mt-2" style="line-height: 1.3;">' . $generate_sub_scores_html($e_sub_scores_formatted, false) . '</div>';
    $s_sub_scores_html = '<div class="text-center small text-muted mt-2" style="line-height: 1.3;">' . $generate_sub_scores_html($s_sub_scores, true) . '</div>';
    $g_sub_scores_html = '<div class="text-center small text-muted mt-2" style="line-height: 1.3;">' . $generate_sub_scores_html($g_sub_scores, true) . '</div>';

    // 【修改處】新增 SDG 圖示
    $sdg_html = generateSdgIconsHtml([8, 12, 13, 16]);
    return <<<HTML
    <div class="card h-100 shadow-sm animate__animated animate__fadeIn">
        <div class="card-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, var(--primary) 0%, color-mix(in srgb, var(--primary) 60%, var(--bs-info)) 100%);">
            <h5 class="mb-0 text-white d-flex align-items-center"><i class="fas fa-tachometer-alt-fast me-2"></i>產品ESG永續績效<span class="badge bg-success-subtle text-success-emphasis ms-2">綜合 (E+S+G)</span></h5>
            {$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="esg-scorecard-v2" title="這代表什麼？" style="cursor: pointer; color: #fff; opacity: 0.8;"></i>
        </div>
        <div class="card-body">
            <div class="row g-4 align-items-center">
                <div class="col-lg-4 text-center border-end">
                    <h6 class="text-muted">ESG 綜合評級</h6>
                    <div class="display-1 fw-bolder text-{$rating['color']}">{$rating['grade']}</div>
                    <div class="badge fs-5 bg-{$rating['color']}-subtle text-{$rating['color']}-emphasis border border-{$rating['color']}-subtle">{$rating['text']}</div>
                    <p class="small text-muted mt-3">綜合分數: <strong>{$esg_score}</strong> / 100</p>
                    <hr>
                    <p class="small px-2">{$rating['desc']}</p>
                </div>
                <div class="col-lg-8">
                    <h6 class="mb-3 text-center">三大構面表現分析</h6>
                    <div class="row text-center">
                        <div class="col-4">
                            <h5 class="mb-2"><i class="fas fa-leaf me-2" style="color: {$e_color};"></i>環境 (E)</h5>
                            {$gauge_svg($e_score, $e_color)}
                            {$e_sub_scores_html}
                        </div>
                        <div class="col-4">
                            <h5 class="mb-2"><i class="fas fa-users me-2" style="color: {$s_color};"></i>社會 (S)</h5>
                            {$gauge_svg($s_score, $s_color)}
                            {$s_sub_scores_html}
                        </div>
                        <div class="col-4">
                            <h5 class="mb-2"><i class="fas fa-landmark me-2" style="color: {$g_color};"></i>治理 (G)</h5>
                            {$gauge_svg($g_score, $g_color)}
                            {$g_sub_scores_html}
                        </div>
                    </div>
                    {$detailed_insight_html}
                </div>
            </div>
        </div>
    </div>
    HTML;
}


/**
 * 【V6.1 關鍵原料整合版】產生「企業永續供應鏈聲譽儀表板」(S+G 主卡)
 * @description 能自動識別並展示關鍵原料風險。
 */
function generate_corporate_reputation_scorecard($social_result, $governance_result): string
{
    if (empty($social_result['success']) || empty($governance_result['success'])) return '';

    $social_score = $social_result['overall_risk_score'];
    $gov_score = $governance_result['overall_risk_score'];
    $combined_score = ($social_score + $gov_score) / 2;
    $combined_score_formatted = number_format($combined_score, 1);

    // --- 【核心升級】整合所有風險類型 ---
    $social_risks = $social_result['unique_risks'];
    if (!empty($social_result['forced_labor_items'])) { array_unshift($social_risks, '[強迫勞動風險] ' . implode(', ', array_map('htmlspecialchars', $social_result['forced_labor_items']))); }
    $gov_risks = $governance_result['unique_risks'];
    if (!empty($governance_result['conflict_mineral_items'])) { foreach ($governance_result['conflict_mineral_items'] as $item) { array_unshift($gov_risks, '[' . htmlspecialchars($item['status']) . ' 風險] ' . htmlspecialchars($item['name'])); } }
    if (!empty($governance_result['critical_raw_material_items'])) { array_unshift($gov_risks, '[關鍵原料供應風險] ' . implode(', ', array_map('htmlspecialchars', $governance_result['critical_raw_material_items']))); }
    $all_risks = array_values(array_unique(array_merge($social_risks, $gov_risks)));


    $all_positives = array_values(array_unique(array_merge($social_result['unique_certifications'], $governance_result['unique_positives'])));

    $rating = ['text' => '高風險', 'color' => 'danger', 'grade' => 'D'];
    if ($combined_score < 25) { $rating = ['text' => '卓越', 'color' => 'success', 'grade' => 'A+']; }
    elseif ($combined_score < 45) { $rating = ['text' => '良好', 'color' => 'primary', 'grade' => 'B']; }
    elseif ($combined_score < 65) { $rating = ['text' => '中度風險', 'color' => 'warning', 'grade' => 'C']; }

    $profile = ['title' => '標準合規者', 'icon' => 'fa-flag', 'color' => 'secondary', 'description' => '您的供應鏈處於行業基準水平，在社會(S)與治理(G)面向上沒有顯著的優點，但也沒有致命的缺點。這是一個中性的起點，也是一個充滿各種優化可能性的「空白畫布」，建議可從風險較高的面向開始著手改善。'];
    if ($social_score < 30 && $gov_score < 30) { $profile = ['title' => 'ESG 聲譽領導者', 'icon' => 'fa-crown', 'color' => 'success', 'description' => '此畫像代表您的供應鏈在社會責任(S)與企業治理(G)兩個面向均展現出低風險與高標準。這不僅是強大的市場競爭壁壘，也是向投資人與客戶證明您擁有系統性永續管理能力的最佳證據。']; }
    elseif ($social_score < 40 && $gov_score >= 60) { $profile = ['title' => '社會責任模範生', 'icon' => 'fa-users', 'color' => 'info', 'description' => '您的供應鏈在社會責任(S)面向表現出色，但在治理(G)風險上仍有改善空間。這是一個常見的發展路徑，顯示公司優先關注了人權、勞工等議題，下一步的重點應是強化治理結構以追求更全面的ESG表現。']; }
    elseif ($social_score >= 60 && $gov_score < 40) { $profile = ['title' => '穩健治理者', 'icon' => 'fa-landmark', 'color' => 'primary', 'description' => '您的供應鏈在企業治理(G)上建立了穩固的基礎，但在社會責任(S)面向存在較高的風險。這意味著您的供應商管理體系可能更側重於合規與財務透明度，建議下一步應加強對勞工權益、社區影響等社會議題的盡職調查。']; }
    elseif ($social_score >= 60 && $gov_score >= 60) { $profile = ['title' => '高風險供應鏈', 'icon' => 'fa-bomb', 'color' => 'danger', 'description' => '警示：您的供應鏈在社会(S)與治理(G)兩個面向均呈現高風險狀態。此畫像代表了顯著的營運、法規與聲譽風險，可能導致供應鏈中斷或品牌價值受損，急需進行全面的風險評估與改善計畫。']; }

    $risksHtml = !empty($all_risks) ? implode('', array_map(fn($r) => "<li class='list-group-item bg-transparent border-0 px-0 py-1'><i class='fas fa-exclamation-triangle text-danger me-2'></i>" . $r . "</li>", array_slice($all_risks, 0, 4))) : "<li class='list-group-item bg-transparent border-0 px-0 py-1'><small class='text-muted'>未識別出顯著的 S/G 風險</small></li>";
    $positivesHtml = !empty($all_positives) ? implode('', array_map(fn($p) => "<li class='list-group-item bg-transparent border-0 px-0 py-1'><i class='fas fa-check-circle text-success me-2'></i>" . htmlspecialchars($p) . "</li>", array_slice($all_positives, 0, 4))) : "<li class='list-group-item bg-transparent border-0 px-0 py-1'><small class='text-muted'>尚無相關的認證或正面實踐</small></li>";
    $getRiskColor = function ($score) { return $score >= 65 ? 'danger' : ($score >= 45 ? 'warning' : 'success'); };

    $sdg_html = generateSdgIconsHtml([8, 10, 16]);
    return <<<HTML
    <div class="card h-100 animate__animated animate__fadeIn"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas fa-building text-primary me-2"></i>永續供應鏈聲譽儀表板<span class="badge bg-warning-subtle text-warning-emphasis ms-2">供應鏈 (S+G)</span></h5>{$sdg_html}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="corporate-reputation-dashboard" title="這代表什麼？" style="cursor: pointer; opacity: 0.8;"></i></div>
        <div class="card-body"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">綜合 S&G 風險評分</h6><div class="display-3 fw-bold text-{$rating['color']}">{$combined_score_formatted}</div><div class="badge fs-6 bg-{$rating['color']}-subtle text-{$rating['color']}-emphasis border border-{$rating['color']}-subtle">{$rating['text']} ( {$rating['grade']} )</div><p class="small text-muted mt-2">(0-100, 分數越低越好)</p></div>
                <div class="col-lg-8"><h6 class="mb-3">聲譽定位：<span class="badge fs-6 text-{$profile['color']} bg-{$profile['color']}-subtle border border-{$profile['color']}-subtle"><i class="fas {$profile['icon']} me-2"></i>{$profile['title']}</span></h6><p class="small text-muted border-start border-2 ps-3">{$profile['description']}</p>
                    <div class="row mt-3"><div class="col-md-6"><div class="d-flex justify-content-between small"><span><i class="fas fa-users text-info fa-fw me-2"></i>社會(S)風險</span><span class="fw-bold text-{$getRiskColor($social_score)}">{$social_score} / 100</span></div><div class="progress" style="height: 8px;"><div class="progress-bar bg-{$getRiskColor($social_score)}" style="width: {$social_score}%;"></div></div></div>
                        <div class="col-md-6"><div class="d-flex justify-content-between small"><span><i class="fas fa-landmark text-secondary fa-fw me-2"></i>治理(G)風險</span><span class="fw-bold text-{$getRiskColor($gov_score)}">{$gov_score} / 100</span></div><div class="progress" style="height: 8px;"><div class="progress-bar bg-{$getRiskColor($gov_score)}" style="width: {$gov_score}%;"></div></div></div></div></div></div>
            <hr><div class="row g-4"><div class="col-md-6"><h6 class="small fw-bold"><i class="fas fa-certificate text-success me-2"></i>聲譽資產 (正面實踐)</h6><ul class="list-group list-group-flush small">{$positivesHtml}</ul></div><div class="col-md-6"><h6 class="small fw-bold"><i class="fas fa-exclamation-triangle text-danger me-2"></i>風險敞口 (已識別)</h6><ul class="list-group list-group-flush small">{$risksHtml}</ul></div></div></div></div>
HTML;
}

// index.php

/**
 * 【V2.0 專家洞察強化版】產生社會責任(S)計分卡的 HTML
 */
function generate_social_scorecard_html(array $socialData): string
{
    if (!isset($socialData['success']) || !$socialData['success']) return '';
    $score = $socialData['overall_risk_score'];
    $sub_scores = $socialData['sub_scores'] ?? [];

    $scoreColorClass = 'text-success'; $scoreText = '低風險';
    if ($score >= 70) { $scoreColorClass = 'text-danger'; $scoreText = '高風險'; }
    else if ($score >= 40) { $scoreColorClass = 'text-warning'; $scoreText = '中度風險'; }

    $renderSubScore = function ($label, $score) {
        $color = $score >= 70 ? 'danger' : ($score >= 40 ? 'warning' : 'success');
        return <<<HTML
        <div class="col-6 mb-3">
            <div class="d-flex justify-content-between small"><span>{$label}</span><span class="fw-bold text-{$color}">{$score} / 100</span></div>
            <div class="progress" style="height: 6px;"><div class="progress-bar bg-{$color}" style="width: {$score}%;"></div></div>
        </div>
HTML;
    };

    $subScoresHtml = '';
    if (!empty($sub_scores)) {
        foreach($sub_scores as $label => $val) {
            $subScoresHtml .= $renderSubScore($label, $val);
        }
    }

    $hotspotsHtml = !empty($socialData['risk_contribution']) ? implode('', array_map(function($item) { return "<li class='list-group-item p-1 bg-transparent border-0'><small>".htmlspecialchars($item['name'])."</small><span class='badge bg-info float-end'>".number_format($item['contribution_pct'], 1)."%</span></li>"; }, array_slice($socialData['risk_contribution'], 0, 3))) : '';

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $insight = '';
    if ($score >= 40 && !empty($sub_scores)) {
        arsort($sub_scores); // 將分數由高到低排序，找出最差的項目
        $weakest_link_name = key($sub_scores);
        $insight = "<strong>策略警示：</strong>數據顯示，造成總體社會風險偏高的主要原因是「<strong class=\"text-danger\">{$weakest_link_name}</strong>」構面表現不佳。建議優先針對此議題，對熱點物料供應商進行盡職調查。";
    } else {
        $insight = "<strong>策略總評：</strong>產品在供應鏈社會責任上表現穩健，未發現顯著的單一風險熱點或結構性問題。";
    }

    $sdg_html = generateSdgIconsHtml([5, 8, 10]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center"><i class="fas fa-users text-primary me-2"></i>社會責任(S)風險細項<span class="badge bg-info-subtle text-info-emphasis ms-2">社會 (S)</span></h5>
            {$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="social-scorecard" title="這代表什麼？"></i>
        </div>
        <div class="card-body d-flex flex-column">
            <div class="row">
                <div class="col-lg-4 text-center border-end">
                    <h6 class="text-muted small">總體風險分數</h6>
                    <p class="display-5 fw-bold {$scoreColorClass}">{$score}</p>
                    <span class="badge bg-{$scoreColorClass}-subtle text-{$scoreColorClass}-emphasis">{$scoreText}</span>
                </div>
                <div class="col-lg-8">
                    <h6 class="small text-muted">細項風險分數 (0-100, 越高越差)</h6>
                    <div class="row">{$subScoresHtml}</div>
                </div>
            </div>
            <hr>
            <div class="row">
                <div class="col-md-6">
                    <h6 class="small text-muted">主要風險貢獻來源 (Top 3)</h6>
                    <ul class="list-group list-group-flush">{$hotspotsHtml}</ul>
                </div>
                <div class="col-md-6">
                    <div class="p-3 bg-light-subtle rounded-3 h-100">
                        <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                        <p class="small text-muted mb-0">{$insight}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
HTML;
}

// index.php

/**
 * 【V2.0 專家洞察強化版】產生企業治理(G)計分卡的 HTML
 */
function generate_governance_scorecard_html(array $governanceData): string
{
    if (!isset($governanceData['success']) || !$governanceData['success']) return '';
    $score = $governanceData['overall_risk_score'];
    $sub_scores = $governanceData['sub_scores'] ?? [];

    $scoreColorClass = 'text-success'; $scoreText = '低風險';
    if ($score >= 60) { $scoreColorClass = 'text-danger'; $scoreText = '高風險'; }
    else if ($score >= 35) { $scoreColorClass = 'text-warning'; $scoreText = '中度風險'; }

    $renderSubScore = function ($label, $score) {
        $color = $score >= 60 ? 'danger' : ($score >= 35 ? 'warning' : 'success');
        return <<<HTML
        <div class="col-6 mb-3">
            <div class="d-flex justify-content-between small"><span>{$label}</span><span class="fw-bold text-{$color}">{$score} / 100</span></div>
            <div class="progress" style="height: 6px;"><div class="progress-bar bg-{$color}" style="width: {$score}%;"></div></div>
        </div>
HTML;
    };

    $subScoresHtml = '';
    if (!empty($sub_scores)) {
        foreach($sub_scores as $label => $val) {
            $subScoresHtml .= $renderSubScore($label, $val);
        }
    }

    $hotspotsHtml = !empty($governanceData['risk_contribution']) ? implode('', array_map(function($item) { return "<li class='list-group-item p-1 bg-transparent border-0'><small>".htmlspecialchars($item['name'])."</small><span class='badge bg-secondary float-end'>".number_format($item['contribution_pct'], 1)."%</span></li>"; }, array_slice($governanceData['risk_contribution'], 0, 3))) : '';

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $insight = '';
    if ($score >= 35 && !empty($sub_scores)) {
        arsort($sub_scores); // 將分數由高到低排序，找出最差的項目
        $weakest_link_name = key($sub_scores);
        $insight = "<strong>策略警示：</strong>數據顯示，造成總體治理風險偏高的主要原因是「<strong class=\"text-danger\">{$weakest_link_name}</strong>」構面表現不佳。建議優先針對此議題，強化供應鏈的透明度與盡職調查。";
    } else {
        $insight = "<strong>策略總評：</strong>產品在供應鏈治理上表現穩健，未發現顯著的單一風險熱點或結構性問題。";
    }

    $sdg_html = generateSdgIconsHtml([16]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center"><i class="fas fa-landmark text-primary me-2"></i>企業治理(G)風險細項<span class="badge bg-secondary-subtle text-secondary-emphasis ms-2">治理 (G)</span></h5>
            {$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="governance-scorecard" title="這代表什麼？"></i>
        </div>
        <div class="card-body d-flex flex-column">
            <div class="row">
                <div class="col-lg-4 text-center border-end">
                    <h6 class="text-muted small">總體風險分數</h6>
                    <p class="display-5 fw-bold {$scoreColorClass}">{$score}</p>
                    <span class="badge bg-{$scoreColorClass}-subtle text-{$scoreColorClass}-emphasis">{$scoreText}</span>
                </div>
                <div class="col-lg-8">
                    <h6 class="small text-muted">細項風險分數 (0-100, 越高越差)</h6>
                    <div class="row">{$subScoresHtml}</div>
                </div>
            </div>
            <hr>
            <div class="row">
                <div class="col-md-6">
                    <h6 class="small text-muted">主要風險貢獻來源 (Top 3)</h6>
                    <ul class="list-group list-group-flush">{$hotspotsHtml}</ul>
                </div>
                <div class="col-md-6">
                    <div class="p-3 bg-light-subtle rounded-3 h-100">
                        <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                        <p class="small text-muted mb-0">{$insight}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
HTML;
}

/**
 * 【V4.0 KPI 專家版】產生綜合財務風險儀表板的 HTML
 * @description 新增頂層 KPI 區塊，優化版面配置，提供最清晰的財務風險視圖。
 */
function generate_financial_risk_summary_html(array $data): string
{
    // --- 1. 數據提取與計算 ---
    $cbam_cost = $data['regulatory_impact']['cbam_cost_twd'] ?? 0;
    $plastic_tax_cost = $data['regulatory_impact']['plastic_tax_twd'] ?? 0;
    $tnfd_var = $data['financial_risk_at_risk']['value_at_risk'] ?? 0;
    $green_premium = 0;

    if (isset($data['commercial_benefits']) && $data['commercial_benefits']['success']) {
        $premium_per_unit = $data['commercial_benefits']['green_premium_per_unit'] ?? 0;
        if ($premium_per_unit > 0) {
            $quantity = $data['inputs']['productionQuantity'] ?? 1;
            $green_premium = $premium_per_unit * $quantity;
        }
    }

    $total_exposure = $cbam_cost + $plastic_tax_cost + $tnfd_var + $green_premium;
    $total_exposure_formatted = number_format($total_exposure, 0);
    $material_cost = ($data['impact']['cost'] ?? 0) * ($data['inputs']['productionQuantity'] ?? 1);

    // --- 2. 計算所有 KPI 指標 ---
    $risk_score = ($material_cost > 0) ? ($total_exposure / $material_cost * 100) : 0;
    $risk_score = min(100, round($risk_score));
    $scoreColor = $risk_score >= 50 ? 'danger' : ($risk_score >= 25 ? 'warning' : 'success');

    $exposure_as_pct_of_profit = 'N/A';
    if (isset($data['commercial_benefits']) && $data['commercial_benefits']['success']) {
        $total_net_profit = $data['commercial_benefits']['total_net_profit'] ?? 0;
        if ($total_net_profit > 0) {
            $exposure_as_pct_of_profit = number_format(($total_exposure / $total_net_profit * 100), 1) . '%';
        } elseif ($total_net_profit <= 0) {
            $exposure_as_pct_of_profit = '<span class="text-danger">虧損中</span>';
        }
    }

    $risk_details = [
        ['label' => '歐盟 CBAM', 'value' => $cbam_cost], ['label' => '歐盟塑膠稅', 'value' => $plastic_tax_cost],
        ['label' => '自然相關風險(VaR)', 'value' => $tnfd_var], ['label' => '綠色材料溢價', 'value' => $green_premium]
    ];
    $risk_details = array_filter($risk_details, fn($item) => $item['value'] > 0);
    usort($risk_details, fn($a, $b) => $b['value'] <=> $a['value']);
    $top_risk_source = empty($risk_details) ? '無' : $risk_details[0]['label'];

    // --- 3. 準備圖表與細項表格的數據 ---
    $chart_data_json = htmlspecialchars(json_encode(array_values($risk_details)), ENT_QUOTES, 'UTF-8');
    $risk_details_html = '';
    if (empty($risk_details)) {
        $risk_details_html = '<tr><td colspan="3" class="text-center text-muted">無顯著風險項目</td></tr>';
    } else {
        foreach ($risk_details as $item) {
            $percentage = ($total_exposure > 0) ? ($item['value'] / $total_exposure * 100) : 0;
            $risk_details_html .= "<tr><td>" . htmlspecialchars($item['label']) . "</td><td class='text-end fw-bold'>" . number_format($item['value'], 0) . "</td><td class='text-end'>" . number_format($percentage, 1) . "%</td></tr>";
        }
    }

    // --- 4. AI 智慧洞察 ---
    $insight = "<strong>風險剖析：</strong>恭喜！目前產品未識別出顯著的永續相關財務風險，展現了卓越的財務韌性。";
    $advice = "<strong>行動建議：</strong>請將此低財務風險的特性，作為您產品的關鍵競爭優勢，並在與投資人或客戶溝通時加以強調。";
    if ($total_exposure > 0) {
        $insight = "<strong>風險剖析：</strong>產品的永續相關財務總曝險約為 <strong>" . number_format($total_exposure, 0) . " TWD</strong>，可能侵蝕掉 <strong>{$exposure_as_pct_of_profit}</strong> 的產品淨利。";
        $advice = "<strong>行動建議：</strong>您的財務團隊應優先針對最大風險來源「<strong>{$top_risk_source}</strong>」制定緩解策略，以保護產品的獲利能力。";
    }

    $sdg_html = generateSdgIconsHtml([8, 16]);
    return <<<HTML
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas fa-coins text-primary me-2"></i>綜合財務風險儀表板<span class="badge bg-primary-subtle text-primary-emphasis ms-2">財務 (F)</span></h5>{$sdg_html}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="financial-risk-summary-dashboard" title="這代表什麼？"></i></div>
        <div class="card-body">
            <div class="row g-3 text-center mb-4">
                <div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100"><h6 class="text-muted small">總曝險金額</h6><p class="fs-4 fw-bold text-danger mb-0">{$total_exposure_formatted} TWD</p></div></div>
                <div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100"><h6 class="text-muted small">財務風險總分</h6><p class="fs-4 fw-bold text-{$scoreColor} mb-0">{$risk_score} / 100</p></div></div>
                <div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100"><h6 class="text-muted small">曝險佔淨利比</h6><p class="fs-4 fw-bold mb-0">{$exposure_as_pct_of_profit}</p></div></div>
                <div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100"><h6 class="text-muted small">最大風險來源</h6><p class="fs-5 fw-bold text-primary mb-0">{$top_risk_source}</p></div></div>
            </div>
            <hr>
            <div class="row g-4 mt-1">
                <div class="col-lg-6">
                    <h6 class="text-muted">風險細項分析 (金額與佔比)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead class="table-light"><tr><th>風險來源</th><th class="text-end">曝險金額 (TWD)</th><th class="text-end">佔比</th></tr></thead>
                            <tbody>{$risk_details_html}</tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-6">
                    <h6 class="text-muted">曝險金額比較 (視覺化)</h6>
                    <div style="height: 220px;"><canvas id="financialRiskSummaryChart" data-risks='{$chart_data_json}'></canvas></div>
                </div>
            </div>
            <hr class="my-3">
            <div class="p-3 bg-light-subtle rounded-3"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-1">{$insight}</p><p class="small text-muted mb-0">{$advice}</p></div>
        </div>
    </div>
HTML;
}

/**
 * 【V3.1 專家升級版 - 原名取代】產生法規風險儀表板的 HTML
 * @description 整合五大關鍵法規，並加入綜合分數與財務風險圖表。
 */
function generate_regulatory_risk_dashboard_html(array $regulatoryData): string
{
    if (empty($regulatoryData['success'])) return '';

    // --- 1. 計算綜合分數 ---
    $score = 100;
    if ($regulatoryData['cbam_cost_twd'] > 1) $score -= 15;
    if ($regulatoryData['plastic_tax_twd'] > 1) $score -= 15;
    if (!empty($regulatoryData['svhc_items'])) $score -= 20;
    if (!empty($regulatoryData['eudr_items'])) $score -= 25;
    if (!empty($regulatoryData['uflpa_items'])) $score -= 25;
    $score = max(0, $score);

    $scoreColor = $score >= 80 ? 'success' : ($score >= 60 ? 'info' : ($score >= 40 ? 'warning' : 'danger'));
    $scoreText = $score >= 80 ? '低風險' : ($score >= 60 ? '輕度風險' : ($score >= 40 ? '中度風險' : '高風險'));

    // --- 2. 準備圖表數據 ---
    $financial_risks = [
        ['label' => '歐盟 CBAM', 'cost' => $regulatoryData['cbam_cost_twd']],
        ['label' => '歐盟塑膠稅', 'cost' => $regulatoryData['plastic_tax_twd']]
    ];
    $chart_data_json = htmlspecialchars(json_encode($financial_risks), ENT_QUOTES, 'UTF-8');

    // --- 3. 升級版 AI 智慧洞察 ---
    $risk_profile = [];
    if ($regulatoryData['cbam_cost_twd'] > 1 || $regulatoryData['plastic_tax_twd'] > 1) $risk_profile[] = '財務成本型';
    if (!empty($regulatoryData['svhc_items'])) $risk_profile[] = '供應鏈合規型';
    if (!empty($regulatoryData['eudr_items']) || !empty($regulatoryData['uflpa_items'])) $risk_profile[] = '市場准入型';

    $insight = '';
    $advice = '';
    if (empty($risk_profile)) {
        $insight = '<strong>風險剖析：</strong>恭喜！您的產品展現了卓越的法規韌性，在五大關鍵法規模擬情境下均為低風險。';
        $advice = '<strong>行動建議：</strong>將此「全方位合規」的特性，作為您產品進入全球市場時的核心競爭優勢與行銷亮點。';
    } else {
        $insight = '<strong>風險剖析：</strong>您的產品主要面臨 <strong class="text-danger">' . implode('、', $risk_profile) . '</strong> 風險。';
        $actions = [];
        if (in_array('財務成本型', $risk_profile)) $actions[] = '優先處理圖表中成本最高的稅務風險，透過提升再生料比例或選用低碳材料來降本';
        if (in_array('供應鏈合規型', $risk_profile)) $actions[] = '立即向 SVHC 相關供應商索取物質安全資料表(SDS)與合規聲明';
        if (in_array('市場准入型', $risk_profile)) $actions[] = '立即針對 EUDR/UFLPA 所涉及的物料啟動供應鏈盡職調查，確保來源的可追溯性與合規性';
        $advice = '<strong>行動建議：</strong><ol class="small ps-3 mb-0"><li>' . implode('</li><li>', $actions) . '</li></ol>';
    }

    // --- 4. 產生各法規區塊的 HTML ---
    $render_block = function($icon, $region, $title, $items, $alert_class, $risk_text, $ok_text) {
        if (!empty($items)) {
            $items_html = implode('、', array_map('htmlspecialchars', $items));
            return "<div class='alert alert-{$alert_class} p-2 small'><i class='fas {$icon} fa-fw me-2'></i><strong>[{$region}] {$title}:</strong> <span class='fw-bold'>風險警示</span><br><small>涉及物料：{$items_html}</small></div>";
        }
        return "<div class='alert alert-secondary p-2 small'><i class='fas {$icon} fa-fw me-2'></i><strong>[{$region}] {$title}:</strong> <span class='text-success'>低風險</span><br><small>{$ok_text}</small></div>";
    };

    $svhc_html = $render_block('fa-biohazard', '歐盟', 'REACH (SVHC)', $regulatoryData['svhc_items'], 'warning', '含有需通報的高度關注物質', '未識別出常見 SVHC');
    $eudr_html = $render_block('fa-tree', '歐盟', '毀林法規 (EUDR)', $regulatoryData['eudr_items'], 'danger', '涉及來自高毀林風險地區的物料', '未涉及典型高毀林風險物料');
    $uflpa_html = $render_block('fa-users-slash', '美國', '防止強迫勞動法 (UFLPA)', $regulatoryData['uflpa_items'], 'danger', '涉及來自高強迫勞動風險地區的物料', '未涉及典型高強迫勞動風險物料');

    $sdg_html = generateSdgIconsHtml([12, 16]);
    return <<<HTML
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas fa-shield-alt text-primary me-2"></i>法規風險儀表板<span class="badge bg-warning-subtle text-warning-emphasis ms-2">法規 (R)</span></h5>{$sdg_html}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="regulatory-risk-dashboard" title="這代表什麼？"></i></div>
        <div class="card-body"><div class="row g-4"><div class="col-lg-3 text-center border-end"><h6 class="text-muted">綜合合規分數</h6><div class="display-3 fw-bold text-{$scoreColor}">{$score}</div><div class="badge fs-6 bg-{$scoreColor}-subtle text-{$scoreColor}-emphasis border border-{$scoreColor}-subtle">{$scoreText}</div><p class="small text-muted mt-2">(0-100, 越高越好)</p></div>
                <div class="col-lg-5 border-end"><h6 class="text-muted">潛在財務風險 (TWD)</h6><div style="height: 150px;"><canvas id="financialRiskChart" data-risks='{$chart_data_json}'></canvas></div></div>
                <div class="col-lg-4"><h6 class="text-muted">市場准入與合規風險</h6>{$svhc_html}{$eudr_html}{$uflpa_html}</div></div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-1">{$insight}</p><div class="small text-muted">{$advice}</div></div></div></div>
HTML;

}

/**
 * 【V2.0 專家升級版】產生循環經濟(C)計分卡的 HTML
 * @description 整合「循環設計潛力」分數與更深入的 AI 洞察。
 */
function generate_circularity_scorecard_html(array $circularity_data): string
{
    if (empty($circularity_data['success'])) return '';

    $score = $circularity_data['mci_score'];
    $design_score = $circularity_data['design_for_recycling_score'] ?? 0; // 【新增】讀取新分數
    $rating = $circularity_data['rating'];
    $breakdown = $circularity_data['breakdown'];

    $score_formatted = number_format($score, 1);
    $recycled_content_pct_formatted = number_format($breakdown['recycled_content_pct'], 1);
    $recycling_rate_pct_formatted = number_format($breakdown['recycling_rate_pct'], 1);

    // 【核心升級】AI 智慧洞察
    $insight = '';
    $gap = $recycling_rate_pct_formatted - $design_score;
    if ($design_score < 30) {
        $insight = "<strong>策略警示：</strong>產品的「循環設計潛力」分數極低 ({$design_score})，意味著其主要由<strong class='text-danger'>難以回收的材料</strong>構成。即使設定了高的回收目標，現實中也難以達成，存在漂綠風險。";
    } elseif ($gap > 20) {
        $insight = "<strong>策略定位：</strong>您的回收目標 ({$recycling_rate_pct_formatted}%) <strong class='text-success'>遠高於</strong>產品的物理回收潛力 ({$design_score}%)。這顯示您有強烈的循環企圖，但需要依賴創新的回收技術或基礎設施來實現。";
    } elseif ($gap < -20) {
        $insight = "<strong>策略機會：</strong>您的回收目標 ({$recycling_rate_pct_formatted}%) <strong class='text-warning'>遠低於</strong>產品的物理回收潛力 ({$design_score}%)。這是一個巨大的機會點，代表您只需優化回收流程，就能輕鬆達成更高的循環績效。";
    } else {
        $insight = "<strong>策略定位：</strong>您的回收目標與產品的物理回收潛力<strong class='text-primary'>高度匹配</strong>。這是一個基於現實、穩健且可信的循環經濟策略。";
    }

    $gauge_svg = function ($score, $score_formatted, $color_var) {
        $radius = 45; $circumference = 2 * M_PI * $radius; $offset = $circumference * (1 - ($score / 100)); $color = "var(--bs-{$color_var})";
        return <<<SVG
        <svg viewBox="0 0 100 100" class="w-100"><circle cx="50" cy="50" r="{$radius}" fill="none" stroke="#e9ecef" stroke-width="10" /><circle cx="50" cy="50" r="{$radius}" fill="none" stroke="{$color}" stroke-width="10" stroke-linecap="round" stroke-dasharray="{$circumference}" stroke-dashoffset="{$offset}" transform="rotate(-90 50 50)" style="transition: stroke-dashoffset 0.8s ease-in-out;"></circle><text x="50" y="55" text-anchor="middle" font-size="24" font-weight="bold" fill="{$color}">{$score_formatted}</text></svg>
SVG;
    };

    $sdg_html = generateSdgIconsHtml([9, 12]);
    return <<<HTML
    <div class="card h-100 shadow-sm animate__animated animate__fadeIn">
        <div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas fa-recycle text-primary me-2"></i>循環經濟計分卡<span class="badge bg-success-subtle text-success-emphasis ms-2">循環 (C)</span></h5>{$sdg_html}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="circularity-scorecard" title="這代表什麼？" style="cursor: pointer; opacity: 0.8;"></i></div>
        <div class="card-body"><div class="row g-4 align-items-center"><div class="col-lg-4 text-center"><h6 class="text-muted">物質循環指數 (MCI)</h6><div style="max-width: 180px; margin: 0 auto;">{$gauge_svg($score, $score_formatted, $rating['color'])}</div><div class="badge fs-6 bg-{$rating['color']}-subtle text-{$rating['color']}-emphasis border border-{$rating['color']}-subtle mt-2">{$rating['text']}</div></div>
                <div class="col-lg-8"><h6>循環流分析</h6>
                    <div class="mb-3"><div class="d-flex justify-content-between small"><span><i class="fas fa-sign-in-alt text-success fa-fw me-2"></i>再生料投入</span><span class="fw-bold">{$recycled_content_pct_formatted}%</span></div><div class="progress" style="height: 8px;"><div class="progress-bar bg-success" style="width: {$breakdown['recycled_content_pct']}%;"></div></div></div>
                    <div class="mb-3"><div class="d-flex justify-content-between small"><span><i class="fas fa-sync-alt text-info fa-fw me-2"></i>終端回收率 (您的目標)</span><span class="fw-bold">{$recycling_rate_pct_formatted}%</span></div><div class="progress" style="height: 8px;"><div class="progress-bar bg-info" style="width: {$breakdown['recycling_rate_pct']}%;"></div></div></div>
                    <div class="mb-3"><div class="d-flex justify-content-between small"><span><i class="fas fa-cogs text-secondary fa-fw me-2"></i>循環設計潛力 (材料物理極限)</span><span class="fw-bold">{$design_score}%</span></div><div class="progress" style="height: 8px;"><div class="progress-bar bg-secondary" style="width: {$design_score}%;"></div></div></div>
                    <hr><div class="p-3 bg-light-subtle rounded-3"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div>
                </div></div></div></div>
HTML;
}

/**
 * 【v2.5 PHP 統一版 - 專家洞察強化版】產生生物多樣性(B)計分卡的 HTML
 */
function generate_biodiversity_scorecard_html(array $bioData): string
{
    if (!isset($bioData['success']) || !$bioData['success']) {
        return '';
    }

    $score = $bioData['performance_score'] ?? 0;
    $scoreColorClass = 'text-success'; $scoreText = '低衝擊';
    if ($score < 40) { $scoreColorClass = 'text-danger'; $scoreText = '高衝擊'; }
    else if ($score < 70) { $scoreColorClass = 'text-warning'; $scoreText = '中度衝擊'; }

    $renderHotspotList = function($hotspotArray, $impactKey) {
        if (empty($hotspotArray)) return '<div class="text-muted small">無顯著熱點。</div>';
        $totalHotspotImpact = array_reduce($hotspotArray, fn($sum, $item) => $sum + ($item[$impactKey] ?? 0), 0);
        if ($totalHotspotImpact <= 1e-9) return '<div class="text-muted small">無顯著熱點。</div>';
        $html = '';
        foreach($hotspotArray as $item) {
            $contributionPct = (($item[$impactKey] ?? 0) / $totalHotspotImpact) * 100;
            $itemName = htmlspecialchars($item['name']);
            $html .= "<div><div class='d-flex justify-content-between small'><span>{$itemName}</span><span class='fw-bold'>" . number_format($contributionPct, 1) . "%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-secondary' style='width: {$contributionPct}%;'></div></div></div>";
        }
        return $html;
    };

    $landUseHotspotsHtml = $renderHotspotList($bioData['hotspots']['land_use'] ?? [], 'land_use');
    $ecotoxHotspotsHtml = $renderHotspotList($bioData['hotspots']['ecotox'] ?? [], 'ecotox');

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $insight = '<strong>策略總評：</strong>產品在生物多樣性上表現穩健，未發現顯著衝擊熱點。';
    if ($score < 70) { // 只要不是低衝擊，就提供洞察
        $pressure_score = $bioData['sub_scores']['pressure_score'] ?? 100;
        $response_score = $bioData['sub_scores']['response_score'] ?? 100;

        if ($response_score < 50) {
            $insight = "<strong>策略警示：</strong>產品的「應對」構面分數過低。這意味著您的供應鏈<strong class='text-danger'>缺乏足夠的正面實踐</strong>（如：FSC森林認證、責任採礦認證等）來緩解其固有的環境衝擊，導致整體分數被拉低。";
        } elseif ($pressure_score < 70) {
            $land_use_hotspot = $bioData['hotspots']['land_use'][0]['name'] ?? null;
            $ecotox_hotspot = $bioData['hotspots']['ecotox'][0]['name'] ?? null;
            // 比較哪個衝擊更嚴重
            $land_use_reduction = 100 - (($bioData['total_land_use_m2a'] / ($bioData['virgin_land_use_m2a'] ?: 1)) * 100);
            $ecotox_reduction = 100 - (($bioData['total_ecotox_ctue'] / ($bioData['virgin_ecotox_ctue'] ?: 1)) * 100);

            if ($land_use_reduction < $ecotox_reduction && $land_use_hotspot) {
                $insight = "<strong>策略定位：</strong>問題根源在於「壓力」構面，特別是熱點物料「<strong class='text-warning'>" . htmlspecialchars($land_use_hotspot) . "</strong>」的<strong class='text-warning'>土地利用衝擊</strong>。這通常與毀林、農業擴張或礦場開採造成的棲息地破壞有關。";
            } elseif ($ecotox_hotspot) {
                $insight = "<strong>策略定位：</strong>問題根源在於「壓力」構面，特別是熱點物料「<strong class='text-warning'>" . htmlspecialchars($ecotox_hotspot) . "</strong>」的<strong class='text-warning'>生態毒性衝擊</strong>。這通常與生產過程中的化學品污染或廢水排放有關。";
            }
        }
    }

    $sdg_html = generateSdgIconsHtml([14, 15]);

    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-paw text-primary me-2"></i>生物多樣性計分卡
                <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>
            </h5>
            {$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="biodiversity-scorecard" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
             <div class="row g-4">
                <div class="col-lg-4 text-center border-end">
                    <h6 class="text-muted">生物多樣性表現</h6>
                    <div class="display-3 fw-bold {$scoreColorClass}">{$score}</div>
                    <p class="small text-muted mt-2">(0-100, 分數越高衝擊越低)</p>
                </div>
                <div class="col-lg-8">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6><i class="fas fa-mountain text-secondary me-2"></i>土地利用熱點 (Top 3)</h6>
                            <div class="d-flex flex-column gap-2 mt-2">{$landUseHotspotsHtml}</div>
                        </div>
                        <div class="col-md-6">
                             <h6><i class="fas fa-tint text-secondary me-2"></i>生態毒性熱點 (Top 3)</h6>
                            <div class="d-flex flex-column gap-2 mt-2">{$ecotoxHotspotsHtml}</div>
                        </div>
                    </div>
                </div>
            </div>
            <hr>
            <div class="p-3 bg-light-subtle rounded-3">
                <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                <p class="small text-muted mb-0">{$insight}</p>
            </div>
        </div>
    </div>
HTML;
}

// index.php

/**
 * 【V2.0 關鍵原料整合版】產生資源消耗 (ADP) 計分卡的 HTML
 */
function generate_resource_depletion_scorecard_html(array $adpData): string
{
    if (!isset($adpData['success']) || !$adpData['success']) {
        return '';
    }

    $performance_score = $adpData['performance_score'] ?? 0;
    $total_impact_kgsbe = $adpData['total_impact_kgsbe'] ?? 0;
    $virgin_impact_kgsbe = $adpData['virgin_impact_kgsbe'] ?? 0;
    $hotspots = $adpData['hotspots'] ?? [];

    $scoreColorClass = 'text-success';
    if ($performance_score < 40) { $scoreColorClass = 'text-danger'; }
    else if ($performance_score < 70) { $scoreColorClass = 'text-warning'; }

    $totalHotspotImpact = array_reduce($hotspots, fn($sum, $item) => $sum + ($item['impact'] ?? 0), 0);
    $hotspotsHtml = '';
    if($totalHotspotImpact > 1e-12) {
        foreach($hotspots as $item) {
            $contributionPct = (($item['impact'] ?? 0) / $totalHotspotImpact) * 100;
            $itemName = htmlspecialchars($item['name']);
            // 【核心升級】如果物料是關鍵原料，則在熱點列表中加上警示圖示
            $critical_icon = ($item['is_critical'] ?? false) ? '<i class="fas fa-exclamation-triangle text-danger ms-2" title="已被列為關鍵原料"></i>' : '';
            $hotspotsHtml .= "<div><div class='d-flex justify-content-between small'><span>{$itemName}{$critical_icon}</span><span class='fw-bold'>" . number_format($contributionPct, 1) . "%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-secondary' style='width: {$contributionPct}%;'></div></div></div>";
        }
    } else {
        $hotspotsHtml = '<div class="text-muted small">無顯著熱點。</div>';
    }

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $hotspot = !empty($hotspots) ? $hotspots[0] : null;
    $insight = '';
    if ($performance_score < 40 && $hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        if ($hotspot['is_critical'] ?? false) {
            $insight = "<strong>策略警示：</strong>產品對稀缺資源構成<strong class=\"text-danger\">極高依賴</strong>。主要消耗來自「{$hotspotName}」，此物料已被歐盟等機構列為「<strong class=\"text-danger\">關鍵原料 (CRM)</strong>」，代表著顯著的供應鏈中斷與價格波動風險。";
        } else {
            $insight = "<strong>策略警示：</strong>產品對稀缺資源構成<strong class=\"text-danger\">高度依賴</strong>。主要消耗來自於「{$hotspotName}」，這可能在未來構成供應鏈與成本波動風險。";
        }
    } elseif ($hotspot) {
        $hotspotName = htmlspecialchars($hotspot['name']);
        $insight = "<strong>策略定位：</strong>產品的資源消耗風險在可控範圍內。主要的改善機會點在於優化「{$hotspotName}」的使用，建議優先為其導入再生材料。";
    } else {
        $insight = "<strong>策略總評：</strong>產品目前的設計在資源消耗上表現優異，未發現對任何單一稀缺資源的顯著依賴，供應鏈韌性良好。";
    }

    $sdg_html = generateSdgIconsHtml([9, 12]);

    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center"><i class="fas fa-gem text-primary me-2"></i>資源消耗計分卡<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>
            {$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="resource-depletion-scorecard" title="這代表什麼？"></i>
        </div>
        <div class="card-body d-flex flex-column">
            <div class="row g-4">
                <div class="col-lg-4 text-center border-end">
                    <h6 class="text-muted">改善分數</h6>
                    <div class="display-4 fw-bold {$scoreColorClass}">{$performance_score}</div>
                </div>
                <div class="col-lg-8">
                    <div class="p-2 bg-light-subtle rounded-3 mb-3">
                        <div class="row text-center gx-1">
                            <div class="col-6 border-end"><small class="text-muted">原生料基準</small><p class="fw-bold mb-0">{$virgin_impact_kgsbe}<small> kg Sb-eq.</small></p></div>
                            <div class="col-6"><small class="text-muted">當前設計</small><p class="fw-bold mb-0 {$scoreColorClass}">{$total_impact_kgsbe}<small> kg Sb-eq.</small></p></div>
                        </div>
                    </div>
                    <h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要貢獻來源 (Top 3)</h6>
                    <div class="d-flex flex-column gap-2 mt-2">{$hotspotsHtml}</div>
                </div>
            </div>
            <hr class="my-3">
            <div class="p-3 bg-light-subtle rounded-3 mt-auto">
                <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                <p class="small text-muted mb-0">{$insight}</p>
            </div>
        </div>
    </div>
HTML;
}

/**
 * 【v7.3 介面統一版 - 專家洞察強化版】產生「TNFD自然風險儀表板」的 HTML
 */
function generate_tnfd_analysis_card_html(array $tnfdData, array $financialRiskData): string
{
    if (empty($tnfdData['success'])) return '';

    $current_risk_score = $tnfdData['overall_risk_score'];
    $virgin_risk_score = $tnfdData['overall_virgin_risk_score'] ?? $current_risk_score;
    $opp_score = $tnfdData['overall_opportunity_score'];
    $value_at_risk = $financialRiskData['value_at_risk'];
    $water_dependency_score = $tnfdData['water_dependency_score'] ?? 50;

    $risk_color = $current_risk_score >= 70 ? 'danger' : ($current_risk_score >= 40 ? 'warning' : 'success');
    $opp_color = $opp_score >= 70 ? 'success' : ($opp_score >= 40 ? 'info' : 'secondary');
    $water_dep_color = $water_dependency_score >= 75 ? 'danger' : ($water_dependency_score >= 50 ? 'warning' : 'success');

    $risk_reduction = $virgin_risk_score - $current_risk_score;
    $risk_display_html = '';
    if ($risk_reduction > 0.1) {
        $risk_display_html = "<small class='text-muted' style='text-decoration: line-through;'>基準 ${virgin_risk_score}</small><div class='display-4 fw-bold text-{$risk_color} d-inline-block'>{$current_risk_score}</div><span class='text-success fw-bold'><i class='fas fa-arrow-down'></i> ".number_format($risk_reduction, 1)."</span>";
    } else {
        $risk_display_html = "<div class='display-4 fw-bold text-{$risk_color}'>{$current_risk_score}</div>";
    }

    $deforestation_items = $tnfdData['deforestation_risk_items'] ?? [];
    $deforestation_alert_html = '';
    if (!empty($deforestation_items)) {
        $items_list = implode('、 ', array_map('htmlspecialchars', array_unique($deforestation_items)));
        $deforestation_alert_html = <<<HTML
        <div class="alert alert-danger d-flex align-items-center mt-3 p-2 small">
            <i class="fas fa-tree fa-2x me-3"></i>
            <div>
                <strong class="alert-heading">高毀林風險警示</strong><br>
                您的 BOM 表中包含來自高毀林風險區的物料：<strong>{$items_list}</strong>。這可能觸發歐盟 EUDR 法規要求，並帶來供應鏈與聲譽風險。
            </div>
        </div>
HTML;
    }

    $dependenciesHtml = !empty($tnfdData['dependencies']) ? implode('', array_map(fn($d) => "<span class='badge bg-info-subtle text-info-emphasis me-1 mb-1'>" . htmlspecialchars($d) . "</span>", $tnfdData['dependencies'])) : "<small class='text-muted'>未識別</small>";
    $impactsHtml = !empty($tnfdData['impact_drivers']) ? implode('', array_map(fn($i) => "<span class='badge bg-warning-subtle text-warning-emphasis me-1 mb-1'>" . htmlspecialchars($i) . "</span>", $tnfdData['impact_drivers'])) : "<small class='text-muted'>未識別</small>";
    $contributorsHtml = '';
    if (!empty($tnfdData['risk_contributors'])) {
        foreach ($tnfdData['risk_contributors'] as $item) {
            $contributorsHtml .= "<div><div class='d-flex justify-content-between small'><span>".htmlspecialchars($item['material_name'])." @ ".htmlspecialchars($item['country_name_zh'])."</span><span class='fw-bold'>".number_format($item['percentage'], 1)."%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-danger' style='width: {$item['percentage']}%;'></div></div></div>";
        }
    } else {
        $contributorsHtml = "<div class='text-muted small'>無顯著的單一風險貢獻來源。</div>";
    }

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $insight = '';
    $hotspot_material_name = htmlspecialchars($tnfdData['risk_contributors'][0]['material_name'] ?? '關鍵原料');

    if (!empty($deforestation_items)) {
        $items_list = implode('、 ', array_map('htmlspecialchars', array_unique($deforestation_items)));
        $insight = "<strong>最高優先級警示：偵測到高毀林風險！</strong>您的BOM表中包含來自高風險地區的物料「<strong class='text-danger'>{$items_list}</strong>」。這可能直接觸發歐盟EUDR法規，導致市場准入問題，應列為最高優先級處理事項。";
    } elseif ($risk_reduction > 5) {
        $insight = "<strong>策略雙贏：</strong>恭喜！您的永續設計非常成功。相較於原生料基準，您已將自然相關風險分數從 {$virgin_risk_score} <strong class='text-success'>成功降低了 ".number_format($risk_reduction, 1)." 分</strong>。";
    } elseif ($current_risk_score >= 70) {
        $insight = "<strong>策略警示：</strong>產品的自然相關風險處於<strong class='text-danger'>高位 ({$current_risk_score})</strong>。潛在的財務曝險價值 (VaR) 為 <strong>".number_format($value_at_risk, 0)." TWD</strong>。首要任務是針對貢獻最大的風險來源「{$hotspot_material_name}」啟動盡職調查。";
    } else {
        $insight = "<strong>策略總評：</strong>產品目前的供應鏈佈局在自然相關風險上表現<strong class='text-success'>穩健 ({$current_risk_score})</strong>，財務曝險低。建議持續探索儀表板所揭示的「自然機會」。";
    }

    $sdg_html = generateSdgIconsHtml([14, 15]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-seedling text-primary me-2"></i>TNFD自然風險儀表板
                <span class="badge bg-success-subtle text-success-emphasis ms-2">自然資本 (E)</span>
            </h5> {$sdg_html}
            <div>
                <button class="btn btn-primary btn-sm" id="open-tnfd-fullscreen-btn">
                    <i class="fas fa-expand me-2"></i>開啟戰情室
                </button>
                <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="tnfd-dashboard" title="這代表什麼？"></i>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 text-center mb-3">
                <div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100"><h6 class="text-muted small">自然風險總分</h6><div style="line-height: 1.1;">{$risk_display_html}</div></div></div>
                <div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100"><h6 class="text-muted small">水資源依賴度</h6><div class="display-4 fw-bold text-{$water_dep_color}">{$water_dependency_score}</div></div></div>
                <div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100"><h6 class="text-muted small">潛在財務風險 (VaR)</h6><div class="display-5 fw-bold text-danger">{$value_at_risk} <small class="fs-5">TWD</small></div></div></div>
                <div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100"><h6 class="text-muted small">自然機會總分</h6><div class="display-4 fw-bold text-{$opp_color}">{$opp_score}</div></div></div>
            </div>
            {$deforestation_alert_html}
            <div class="row g-4 mt-1"><div class="col-lg-4"><h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要風險貢獻來源</h6><div class="d-flex flex-column gap-2 mt-2">{$contributorsHtml}</div></div><div class="col-lg-4"><h6><i class="fas fa-exclamation-triangle text-danger me-2"></i>主要自然風險 (Top 5)</h6><div style="height: 180px;"><canvas id="tnfdRiskChart"></canvas></div></div><div class="col-lg-4"><h6><i class="fas fa-leaf text-success me-2"></i>主要自然機會 (Top 5)</h6><div style="height: 180px;"><canvas id="tnfdOpportunityChart"></canvas></div></div></div><hr>
            <div class="row g-4"><div class="col-lg-7"><h6><i class="fas fa-sitemap text-secondary me-2"></i>依賴與衝擊路徑</h6><div class="p-3 bg-light-subtle rounded-3 small"><strong class="text-info-emphasis">企業依賴於:</strong> <div>{$dependenciesHtml}</div><div class="my-2"><i class="fas fa-long-arrow-alt-down mx-auto d-block text-center text-muted"></i></div><strong class="text-warning-emphasis">進而對自然產生衝擊:</strong> <div>{$impactsHtml}</div></div></div>
                <div class="col-lg-5"><h6><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><div class="p-3 bg-light-subtle rounded-3 h-100"><p class="small text-muted mb-0">{$insight}</p></div></div>
            </div>
        </div>
    </div>
HTML;
}

/**
 * 【v7.3 介面統一版 - 單一組件邏輯強化版】產生「氣候行動」計分卡的 HTML
 */
function generate_climate_action_scorecard_html(array $data, array $environmental_performance): string
{
    $climate_score = $environmental_performance['breakdown']['climate'] ?? 0;
    $score_color = $climate_score >= 75 ? 'success' : ($climate_score >= 50 ? 'primary' : 'warning');

    $hotspot = null;
    $composition_data = $data['charts']['composition'] ?? [];
    if (!empty($composition_data)) {
        $hotspot_array = $composition_data;
        usort($hotspot_array, fn($a, $b) => ($b['co2'] ?? 0) <=> ($a['co2'] ?? 0));
        $hotspot = $hotspot_array[0];
    }

    $total_co2 = $data['impact']['co2'] ?? 0;
    $hotspot_contribution_pct = ($hotspot && $total_co2 != 0) ? ((($hotspot['co2'] ?? 0) / $total_co2) * 100) : 0;

    $sequestration = $data['charts']['lifecycle_co2']['sequestration'] ?? 0;

    // 【核心升級】全新的 AI 智慧洞察邏輯
    $insight = '';
    if ($sequestration > 0.001) {
        $insight = "<strong>策略亮點：</strong>您的產品透過使用生物基材料，成功在生命週期前端捕獲了 <strong>" . number_format($sequestration, 2) . " kg</strong> 的生物源碳，這是一項卓越的氣候正效益，也是強而有力的行銷故事。";
    } elseif ($hotspot) {
        // 新增判斷式：檢查是否為單一組件
        if (count($composition_data) === 1) {
            $insight = "<strong>策略定位：</strong>產品的總碳足跡完全由單一組件<strong>「" . htmlspecialchars($hotspot['name']) . "」</strong>貢獻。所有減碳策略都必須圍繞此核心材料進行，例如提升其再生比例或尋找創新替代方案。";
        } elseif ($hotspot_contribution_pct > 50) {
            $insight = "<strong>策略警示：</strong>產品的氣候績效存在明顯的「單點故障」風險。超過一半的碳足跡都來自於單一組件：<strong>「" . htmlspecialchars($hotspot['name']) . "」</strong>。";
        } else {
            $insight = "<strong>策略定位：</strong>產品的碳足跡來源較為分散，最主要的熱點<strong>「" . htmlspecialchars($hotspot['name']) . "」</strong>貢獻了約 " . number_format($hotspot_contribution_pct, 0) . "% 的衝擊。建議從此熱點開始優化。";
        }
    } else {
        $insight = "<strong>策略總評：</strong>產品的碳足跡分佈非常健康，未發現顯著的單一衝擊熱點。";
    }

    $sdg_html = generateSdgIconsHtml([7, 13]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-smog text-primary me-2"></i>氣候行動計分卡
                <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>
            </h5>{$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="climate-action-scorecard" title="這代表什麼？"></i>
        </div>
        <div class="card-body"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">氣候行動分數</h6><div class="display-3 fw-bold text-{$score_color}">{$climate_score}</div><p class="small text-muted mt-2">(0-100, 越高越好)</p></div>
                <div class="col-lg-8"><div class="row"><div class="col-md-6"><h6 class="text-muted text-center">生命週期階段佔比</h6><div style="height: 150px;"><canvas id="lifecycleBreakdownChart"></canvas></div></div><div class="col-md-6"><h6 class="text-muted text-center">主要碳排貢獻來源 (Top 5)</h6><div style="height: 150px;"><canvas id="carbonHotspotChart"></canvas></div></div></div></div></div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3 mx-3 mb-3"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div></div></div>
HTML;
}

/**
 * 【v7.3 介面統一版】產生綜合「循環經濟」計分卡的 HTML
 */
function generate_comprehensive_circularity_scorecard_html(array $data, array $environmental_performance): string
{
    $circ_score = $environmental_performance['breakdown']['circularity'] ?? 0;
    $score_color = $circ_score >= 75 ? 'success' : ($circ_score >= 50 ? 'primary' : 'warning');

    $mci_score = $data['circularity_analysis']['mci_score'] ?? 0;
    $waste_score = $environmental_performance['sub_scores_for_debug']['waste_score'] ?? ($environmental_performance['breakdown']['circularity'] ?? 0);
    $adp_score = $data['resource_depletion_impact']['performance_score'] ?? 0;

    $sub_scores = ['MCI (產品循環設計)' => $mci_score, '生產廢棄物改善' => $waste_score, '資源消耗(ADP)改善' => $adp_score];

    $weakest_link = '未知';
    if (!empty($sub_scores)) {
        arsort($sub_scores);
        $weakest_link = array_keys($sub_scores)[count($sub_scores)-1];
    }

    $insight = "<strong>策略定位：</strong>您的產品在循環經濟上表現良好。若要追求卓越，目前的瓶頸在於<strong>「{$weakest_link}」</strong>，建議將優化資源集中於此，以最有效率地提升總分。";

    $sdg_html = generateSdgIconsHtml([9, 12]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-recycle text-primary me-2"></i>綜合循環經濟計分卡
                <span class="badge bg-primary-subtle text-primary-emphasis ms-2">循環 (C)</span>
            </h5>{$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="comprehensive-circularity-scorecard" title="這代表什麼？"></i>
        </div>
        <div class="card-body"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">綜合循環經濟分數</h6><div class="display-3 fw-bold text-{$score_color}">{$circ_score}</div><p class="small text-muted mt-2">(0-100, 越高越好)</p></div>
                <div class="col-lg-8"><h6 class="text-muted">三大核心構面分析</h6><div style="height: 180px;"><canvas id="circularityBreakdownChart"></canvas></div></div></div>
             <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3 mx-3 mb-3"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">{$insight}</p></div></div></div>
HTML;
}

/**
 * 【V7.1 - 擴充版】產生「綜合污染防治計分卡」的 HTML
 * @description 深度剖析產品在【五大】污染指標上的表現，包含生態毒性。
 * @param array $data - 來自後端的完整計算結果 (此參數保留以備未來擴充，目前未使用)
 * @param array $environmental_performance - 來自 calculate_environmental_performance 的結果
 * @return string - 完整的卡片 HTML
 */
function generate_pollution_prevention_scorecard_html(array $data, array $environmental_performance): string
{
    $pollution_score = $environmental_performance['breakdown']['pollution'] ?? 0;
    $score_color = $pollution_score >= 75 ? 'success' : ($pollution_score >= 50 ? 'primary' : 'warning');

    // AI 智慧洞察：找出最弱的環節
    $sub_scores = $environmental_performance['sub_scores_for_debug']['pollution_sub_scores'] ?? [];
    $insight = '產品的污染防治表現數據不足，無法提供具體建議。';
    if (!empty($sub_scores)) {
        asort($sub_scores); // 從分數最低的開始排序
        $weakest_link = key($sub_scores);
        $insight = "<strong>策略定位：</strong>數據顯示，主要的改善機會點在於<strong>「{$weakest_link}」</strong>，解決此項目的污染源，將是提升整體污染防治分數的關鍵。";
    }

    $sdg_html = generateSdgIconsHtml([3, 11, 14, 15]);
    return <<<HTML
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-biohazard text-primary me-2"></i>綜合污染防治計分卡<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>{$sdg_html}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="pollution-prevention-overview" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-4 text-center border-end">
                    <h6 class="text-muted">污染防治總分</h6>
                    <div class="display-3 fw-bold text-{$score_color}">{$pollution_score}</div>
                    <p class="small text-muted mt-2">(0-100, 越高越好)</p>
                </div>
                <div class="col-lg-8">
                    <h6 class="text-muted">五大污染類型表現</h6>
                    <div style="height: 180px;"><canvas id="pollutionBreakdownChart"></canvas></div>
                </div>
            </div>
            <hr class="my-3">
            <div class="p-3 bg-light-subtle rounded-3">
                <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                <p class="small text-muted mb-0">{$insight}</p>
            </div>
        </div>
    </div>
HTML;
}

/**
 * 【V12.4 - 最終除錯與穩健版】處理前端發送的主要計算請求
 * @description 1. 確保下游分析模組只接收純物料BOM。
 * @description 2. 加入輸出緩衝區(Output Buffering)，徹底杜絕 Notice 污染 JSON 的問題。
 * @param PDO $db The database connection object.
 */
function handle_calculation_request($db) {
    // 將 header 移至 try 區塊外，確保無論如何都是 JSON 回應
    header('Content-Type: application/json; charset=utf-8');

    // ▼▼▼ 【防護層二：輸出緩衝區】開始緩衝，攔截所有非預期的輸出 ▼▼▼
    ob_start();

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $components = $input['components'] ?? [];

        // --- (函式開頭的變數宣告與 use_phase 處理邏輯保持不變) ---
        $eol = $input['eol'] ?? ['recycle' => 100, 'incinerate' => 0, 'landfill' => 0];
        $productionQuantity = (int)($input['inputs']['productionQuantity'] ?? 1);
        $sellingPrice = (float)($input['inputs']['sellingPrice'] ?? 0);
        $manufacturingCost = (float)($input['inputs']['manufacturingCost'] ?? 0);
        $sgaCost = (float)($input['inputs']['sgaCost'] ?? 0);
        $use_phase_data = $input['use_phase'] ?? [];
        $transport_data = $input['transport_phase'] ?? [];

        // 【修正處】
        $use_phase_enabled = $use_phase_data['enabled'] ?? false;
        $scenarioKey = $use_phase_data['scenarioKey'] ?? null;

        if ($use_phase_enabled && $scenarioKey) {
            $scenarios_content = file_get_contents(__DIR__ . '/use_phase_scenarios.json');
            $scenarios_data = json_decode($scenarios_content, true);
            $all_scenarios = array_merge(...array_values($scenarios_data));
            $selected_scenario = null;
            foreach ($all_scenarios as $s) { if ($s['key'] === $scenarioKey) { $selected_scenario = $s; break; } }
            if ($selected_scenario && !empty($selected_scenario['implicit_bom'])) {
                foreach ($selected_scenario['implicit_bom'] as $implicit_item) {
                    $components[] = [
                        'componentType' => 'material',
                        'materialKey' => $implicit_item['key'],
                        'weight' => ($implicit_item['weight_g_per_unit'] ?? 0) / 1000,
                        'percentage' => 0, 'cost' => ''
                    ];
                }
            }
        }

        if (empty($components)) {
            throw new Exception('物料清單(BOM)不可為空。');
        }

        $grid_factors_content = file_get_contents(__DIR__ . '/grid_factors.json');
        $grid_factors = json_decode($grid_factors_content, true);

        $materialKeys = array_unique(array_column(array_filter($components, fn($c) => ($c['componentType'] ?? 'material') === 'material'), 'materialKey'));
        if (!empty($materialKeys)) {
            $placeholders = rtrim(str_repeat('?,', count($materialKeys)), ',');
            $sql = "SELECT * FROM materials WHERE key IN ($placeholders)";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($materialKeys));
            $materials_map = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'key');
        } else { $materials_map = []; }

        $processKeys = array_unique(array_column(array_filter($components, fn($c) => $c['componentType'] === 'process'), 'processKey'));
        $processes_map = [];
        if (!empty($processKeys)) {
            $placeholders_proc = rtrim(str_repeat('?,', count($processKeys)), ',');
            $sql_proc = "SELECT * FROM processes WHERE process_key IN ($placeholders_proc)";
            $stmt_proc = $db->prepare($sql_proc);
            $stmt_proc->execute(array_values($processKeys));
            $processes_data_raw = $stmt_proc->fetchAll(PDO::FETCH_ASSOC);
            $stmt_opts = $db->query("SELECT process_key, id, option_key, option_name FROM process_options");
            $options_by_proc_key = $stmt_opts->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
            $stmt_mults = $db->query("SELECT option_id, item_key, item_name, energy_multiplier FROM process_option_multipliers");
            $multipliers_by_opt_id = $stmt_mults->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
            foreach($processes_data_raw as $proc) {
                $proc_key = $proc['process_key'];
                $proc['options'] = [];
                if (isset($options_by_proc_key[$proc_key])) {
                    foreach ($options_by_proc_key[$proc_key] as $opt) {
                        $option_id = $opt['id'];
                        $option_key = $opt['option_key'];
                        $choices = $multipliers_by_opt_id[$option_id] ?? [];
                        $formatted_choices = array_map(function($choice) { return [ 'key' => $choice['item_key'], 'name' => $choice['item_name'], 'energy_multiplier' => (float)$choice['energy_multiplier'] ]; }, $choices);
                        $proc['options'][$option_key] = [ 'name' => $opt['option_name'], 'choices' => $formatted_choices ];
                    }
                }
                $processes_map[$proc_key] = $proc;
            }
        }

        $result = calculate_lca_from_bom($components, $eol, $materials_map, $processes_map, $use_phase_data, $transport_data, $grid_factors);
        if (empty($result['success'])) { throw new Exception($result['error'] ?? '核心LCA計算失敗。'); }

        // ▼▼▼ 【防護層一：邏輯修正】建立一個只包含「物料」的清單 ▼▼▼
        $material_components = array_filter($components, fn($c) => ($c['componentType'] ?? 'material') === 'material');

        // --- 後續所有分析模組的呼叫，都使用這個純物料清單 ---
        $modifiers = [];
        $final_response = $result;

        $social_result = calculate_social_impact($material_components, $materials_map, $modifiers);
        $final_response['social_impact'] = $social_result;

        $governance_result = calculate_governance_impact($material_components, $materials_map, $modifiers);
        $final_response['governance_impact'] = $governance_result;

        $sg_hotspots = calculate_sg_hotspots($social_result, $governance_result);
        $final_response['sg_hotspots'] = $sg_hotspots;

        $waste_impact_result = calculate_waste_impact($material_components, $materials_map);
        $final_response['waste_impact'] = $waste_impact_result;
        $final_response['waste_scorecard_html'] = generate_waste_scorecard_html($waste_impact_result);

        $energy_impact_result = calculate_energy_impact($material_components, $materials_map);
        $final_response['energy_impact'] = $energy_impact_result;
        $final_response['energy_scorecard_html'] = generate_energy_scorecard_html($energy_impact_result);

        $acidification_impact_result = calculate_acidification_impact($material_components, $materials_map);
        $final_response['acidification_impact'] = $acidification_impact_result;
        $final_response['acidification_scorecard_html'] = generate_acidification_scorecard_html($acidification_impact_result);

        $eutrophication_impact_result = calculate_eutrophication_impact($material_components, $materials_map);
        $final_response['eutrophication_impact'] = $eutrophication_impact_result;
        $final_response['eutrophication_scorecard_html'] = generate_eutrophication_scorecard_html($eutrophication_impact_result);

        $ozone_depletion_impact_result = calculate_ozone_depletion_impact($material_components, $materials_map);
        $final_response['ozone_depletion_impact'] = $ozone_depletion_impact_result;
        $final_response['ozone_depletion_scorecard_html'] = generate_ozone_depletion_scorecard_html($ozone_depletion_impact_result);

        $photochemical_ozone_impact_result = calculate_photochemical_ozone_impact($material_components, $materials_map);
        $final_response['photochemical_ozone_impact'] = $photochemical_ozone_impact_result;
        $final_response['photochemical_ozone_scorecard_html'] = generate_photochemical_ozone_scorecard_html($photochemical_ozone_impact_result);

        $water_scarcity_result = calculate_water_scarcity_impact($material_components, $materials_map);
        $final_response['water_scarcity_impact'] = $water_scarcity_result;

        $biodiversity_result = calculate_biodiversity_impact($material_components, $materials_map);
        $final_response['biodiversity_impact'] = $biodiversity_result;

        $resource_depletion_result = calculate_resource_depletion_impact($material_components, $materials_map);
        $final_response['resource_depletion_impact'] = $resource_depletion_result;

        $final_response['biodiversity_scorecard_html'] = generate_biodiversity_scorecard_html($biodiversity_result);
        $final_response['resource_depletion_scorecard_html'] = generate_resource_depletion_scorecard_html($resource_depletion_result);

        $tnfd_result = calculate_tnfd_analysis($material_components, $materials_map);
        $financial_risk_result = calculate_financial_risk_at_risk($material_components, $materials_map, $tnfd_result);
        $final_response['tnfd_analysis'] = $tnfd_result;
        $final_response['financial_risk_at_risk'] = $financial_risk_result;
        $final_response['tnfd_analysis_html'] = generate_tnfd_analysis_card_html($tnfd_result, $financial_risk_result);

        $regulatory_result = calculate_regulatory_impact($material_components, $result, $materials_map);
        $final_response['regulatory_impact'] = $regulatory_result;
        $final_response['regulatory_risk_dashboard_html'] = generate_regulatory_risk_dashboard_html($regulatory_result);

        $circularity_result = calculate_circularity_score($result, $materials_map);
        $final_response['circularity_analysis'] = $circularity_result;
        $final_response['circularity_scorecard_html'] = generate_circularity_scorecard_html($circularity_result);

        $environmental_performance = calculate_environmental_performance($result, $circularity_result, $biodiversity_result, $water_scarcity_result, $resource_depletion_result);
        $final_response['environmental_performance'] = $environmental_performance;
        $final_response['environmental_performance_overview_html'] = generate_environmental_performance_overview_html($result, $environmental_performance);
        $final_response['water_management_scorecard_html'] = generate_water_management_scorecard_html($result, $environmental_performance, $water_scarcity_result, $tnfd_result);
        $final_response['water_scarcity_scorecard_html'] = generate_water_scarcity_scorecard_html($water_scarcity_result);
        $final_response['total_water_footprint_card_html'] = generate_total_water_footprint_card_html($result);

        $esg_scores = calculate_esg_score($environmental_performance, $social_result, $governance_result, $biodiversity_result, $water_scarcity_result, $resource_depletion_result);
        $final_response['esg_scores'] = $esg_scores;

        if ($sellingPrice > 0) {
            $commercial_result = calculate_commercial_benefits($result, $sellingPrice, $productionQuantity, $manufacturingCost, $sgaCost);
            if ($commercial_result['success']) { $final_response['commercial_benefits'] = $commercial_result; }
        }

        $final_response['esg_scorecard_html'] = generate_esg_scorecard_html($esg_scores);
        $final_response['corporate_reputation_html'] = generate_corporate_reputation_scorecard($social_result, $governance_result);
        $final_response['social_scorecard_html'] = generate_social_scorecard_html($social_result);
        $final_response['governance_scorecard_html'] = generate_governance_scorecard_html($governance_result);
        $final_response['story_score'] = calculate_storytelling_score($final_response, $social_result);
        $final_response['versionName'] = $input['versionName'] ?? '計算結果';
        $final_response['inputs']['projectId'] = $input['inputs']['projectId'] ?? 'new';
        $final_response['inputs']['newProjectName'] = $input['inputs']['newProjectName'] ?? '';
        $final_response['inputs']['productionQuantity'] = $productionQuantity;
        $final_response['inputs']['sellingPrice'] = $sellingPrice;
        $final_response['inputs']['manufacturingCost'] = $manufacturingCost;
        $final_response['inputs']['sgaCost'] = $sgaCost;
        $final_response['metadata'] = ['timestamp' => date('Y-m-d H:i:s'), 'factor_source_used' => 'all'];
        $final_response['view_id'] = null;
        $final_response['holistic_analysis'] = generate_holistic_analysis_php($final_response);
        $final_response['climate_action_scorecard_html'] = generate_climate_action_scorecard_html($final_response, $environmental_performance);
        $final_response['comprehensive_circularity_scorecard_html'] = generate_comprehensive_circularity_scorecard_html($final_response, $environmental_performance);
        $final_response['pollution_prevention_scorecard_html'] = generate_pollution_prevention_scorecard_html($final_response, $environmental_performance);
        $final_response['comprehensive_sg_dashboard_html'] = generate_comprehensive_sg_dashboard_html();
        $final_response['sankey_deep_dive_html'] = generate_sankey_deep_dive_html();
        $final_response['financial_risk_summary_html'] = generate_financial_risk_summary_html($final_response);
        $final_response['story_sdgs'] = identifyRelevantSDGs($final_response);
        $final_response['materials_map'] = $materials_map;

        // 【防護層二】清除緩衝區，確保只輸出我們想要的 JSON
        ob_end_clean();
        send_json_response($final_response);

    } catch (Throwable $e) {
        // 【防護層二】如果發生錯誤，也要清除緩衝區，然後才輸出錯誤訊息
        ob_end_clean();

        // 【核心除錯功能】捕捉任何致命錯誤並回傳詳細資訊
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => '後端計算時發生致命錯誤，請將下方除錯資訊回報給開發人員。',
            'debug_info' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * 【全新】處理生成式AI摘要請求的 API 端點
 */
function handle_generate_narrative_request() {
    // 【核心修改】接收包含 reportData 和 persona 的新 payload
    $payload = json_decode(file_get_contents('php://input'), true);
    $reportData = $payload['reportData'] ?? null;
    $persona = $payload['persona'] ?? 'consultant'; // 預設為顧問

    if (empty($reportData)) {
        send_json_response(['success' => false, 'message' => '缺少報告數據。']);
        return;
    }
    // 將選擇的人格傳遞給核心函式
    $result = generate_ai_narrative($reportData, $persona);
    send_json_response($result);
}/**
 * 【全新】處理 AI 後續對話請求的 API 端點
 */
function handle_chat_follow_up_request() {
    $payload = json_decode(file_get_contents('php://input'), true);
    $reportData = $payload['reportData'] ?? null;
    $persona = $payload['persona'] ?? 'consultant';
    $chatHistory = $payload['chatHistory'] ?? [];
    $newQuestion = $payload['newQuestion'] ?? '';

    if (empty($reportData) || empty($newQuestion)) {
        send_json_response(['success' => false, 'message' => '缺少報告數據或新的提問。']);
        return;
    }

    // 將選擇的人格與對話歷史傳遞給核心函式
    $result = generate_ai_follow_up_response($reportData, $persona, $chatHistory, $newQuestion);
    send_json_response($result);
}

/**
 * 【V12.2 - Final & Complete】處理儲存報告的請求
 * @description 處理新專案建立時的組織歸屬，並強制要求舊專案在儲存新版本前必須完成組織指派。
 * @param PDO $db The database connection object.
 */
function handle_save_report_request($db) {
    $reportData = json_decode(file_get_contents('php://input'), true);
    if (!$reportData || !isset($reportData['versionName'])) {
        send_json_response(['success' => false, 'message' => '無效的報告數據，缺少版本名稱。']);
        return;
    }
    try {
        $db->beginTransaction();
        $user_id = $_SESSION['user_id'];
        $projectIdInput = $reportData['inputs']['projectId'] ?? 'new';
        $newProjectName = trim($reportData['inputs']['newProjectName'] ?? '');
        $versionName = trim($reportData['versionName']);
        if(empty($versionName)) { $versionName = '未命名版本'; }

        $finalProjectId = $projectIdInput;
        $finalProjectName = '';
        $newlyCreatedProject = null;

        if ($projectIdInput === 'new') {
            if (empty($newProjectName)) {
                $db->rollBack();
                send_json_response(['success' => false, 'message' => '新專案名稱不可為空。']);
                return;
            }

            $target_organization_id = (int)($reportData['inputs']['organizationId'] ?? 0);

            if (empty($target_organization_id) || $target_organization_id <= 0) {
                $db->rollBack();
                send_json_response(['success' => false, 'message' => '建立新專案前，請務必在頂部選擇一個所屬組織。']);
                return;
            }

            $stmt_org_check = $db->prepare("SELECT id FROM organizations WHERE id = ? AND user_id = ?");
            $stmt_org_check->execute([$target_organization_id, $user_id]);
            if (!$stmt_org_check->fetch()) {
                $db->rollBack();
                send_json_response(['success' => false, 'message' => '權限不足，無法在所選組織下建立專案。']);
                return;
            }

            $stmt_check = $db->prepare("SELECT id FROM projects WHERE name = ? AND organization_id = ?");
            $stmt_check->execute([$newProjectName, $target_organization_id]);
            if ($stmt_check->fetch()) {
                $db->rollBack();
                send_json_response(['success' => false, 'message' => "專案名稱 '{$newProjectName}' 在您的組織下已存在。"]);
                return;
            }

            $stmt_proj = $db->prepare("INSERT INTO projects (name, user_id, organization_id) VALUES (?, ?, ?)");
            $stmt_proj->execute([$newProjectName, $user_id, $target_organization_id]);

            $finalProjectId = $db->lastInsertId();
            $finalProjectName = $newProjectName;
            $newlyCreatedProject = ['id' => $finalProjectId, 'name' => $finalProjectName];

        } else {
            $stmt_check_proj = $db->prepare("SELECT name, organization_id FROM projects WHERE id = ? AND user_id = ?");
            $stmt_check_proj->execute([$finalProjectId, $user_id]);
            $project_info = $stmt_check_proj->fetch(PDO::FETCH_ASSOC);

            if (!$project_info) {
                throw new Exception("權限不足或找不到 ID 為 {$finalProjectId} 的專案。");
            }

            if (empty($project_info['organization_id'])) {
                $db->rollBack();
                send_json_response(['success' => false, 'message' => '儲存失敗：此為舊專案，請先在主畫面為其「指派組織」，然後再儲存新版本。']);
                return;
            }
            $finalProjectName = $project_info['name'];
        }

        $view_id = uniqid('report_', true);
        $reportData['view_id'] = $view_id;
        file_put_contents(RESULTS_DIR . '/' . $view_id . '.json', json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $stmt_insert = $db->prepare("INSERT INTO reports (view_id, project_id, version_name, project_name, total_weight_kg, total_co2e_kg, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_insert->execute([
            $view_id, $finalProjectId, $versionName, $finalProjectName,
            round($reportData['inputs']['totalWeight'], 3), round($reportData['impact']['co2'], 3), $user_id
        ]);

        if ($finalProjectId > 0 && !empty($reportData['versionName'])) {
            $stmt_org = $db->prepare("SELECT organization_id FROM projects WHERE id = ? AND user_id = ?");
            $stmt_org->execute([$finalProjectId, $user_id]);
            $target_organization_id = $stmt_org->fetchColumn();

            if ($target_organization_id) {
                $product_key = $reportData['versionName'];

                $total_weight = $reportData['inputs']['totalWeight'];
                $primary_data_weight = 0;

                // 【⬇️ 核心修正】只針對物料 (material) 類型的組件進行迭代
                if (isset($reportData['inputs']['components']) && is_array($reportData['inputs']['components'])) {
                    $material_components = array_filter($reportData['inputs']['components'], fn($c) => ($c['componentType'] ?? 'material') === 'material');

                    foreach ($material_components as $component) {
                        $material_key = $component['materialKey'];
                        $stmt_mat = $db->prepare("SELECT data_source FROM materials WHERE key = ?");
                        $stmt_mat->execute([$material_key]);
                        $material_source = $stmt_mat->fetchColumn();
                        if ($material_source && stripos($material_source, '一級數據') !== false) {
                            $primary_data_weight += (float)($component['weight'] ?? 0);
                        }
                    }
                }

                $primary_data_percentage = ($total_weight > 0) ? ($primary_data_weight / $total_weight) * 100 : 0;
                $data_quality_score = 50 + ($primary_data_percentage / 2);
                $data_origin_type = '二級數據';
                if ($primary_data_percentage >= 99.9) { $data_origin_type = '一級數據'; }
                elseif ($primary_data_percentage >= 0.1) { $data_origin_type = '混合式數據'; }

                // 【⬇️ 核心修正】更穩健地取得產品分類
                $first_material_in_composition = null;
                if(isset($reportData['charts']['composition']) && is_array($reportData['charts']['composition'])) {
                    foreach($reportData['charts']['composition'] as $comp_item){
                        if(isset($comp_item['weight']) && $comp_item['weight'] > 0) {
                            $first_material_in_composition = $comp_item;
                            break;
                        }
                    }
                }
                $product_category = $first_material_in_composition['category'] ?? '未分類';


                $params = [
                    ':organization_id' => $target_organization_id,
                    ':product_key' => $product_key,
                    ':name' => $reportData['versionName'],
                    ':category' => $product_category,
                    ':total_weight_kg' => round($reportData['inputs']['totalWeight'], 5),
                    ':total_co2e_kg_per_unit' => round($reportData['impact']['co2'], 5),
                    ':lca_report_view_id' => $view_id,
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':data_quality_score' => round($data_quality_score),
                    ':data_origin_type' => $data_origin_type
                ];

                $sql_upsert_product = "
                    INSERT INTO products (organization_id, product_key, name, category, total_weight_kg, total_co2e_kg_per_unit, lca_report_view_id, updated_at, data_quality_score, data_origin_type)
                    VALUES (:organization_id, :product_key, :name, :category, :total_weight_kg, :total_co2e_kg_per_unit, :lca_report_view_id, :updated_at, :data_quality_score, :data_origin_type)
                    ON CONFLICT(organization_id, product_key) DO UPDATE SET
                        name = excluded.name,
                        category = excluded.category,
                        total_weight_kg = excluded.total_weight_kg,
                        total_co2e_kg_per_unit = excluded.total_co2e_kg_per_unit,
                        lca_report_view_id = excluded.lca_report_view_id,
                        updated_at = excluded.updated_at,
                        data_quality_score = excluded.data_quality_score,
                        data_origin_type = excluded.data_origin_type;
                ";

                $stmt_product = $db->prepare($sql_upsert_product);
                $stmt_product->execute($params);
            }
        }

        $db->commit();

        $response_payload = ['success' => true, 'message' => '報告已成功儲存！', 'view_id' => $view_id];
        if ($newlyCreatedProject) {
            $response_payload['newProject'] = $newlyCreatedProject;
        }
        send_json_response($response_payload);

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        send_json_response(['success' => false, 'message' => '儲存報告時發生錯誤: ' . $e->getMessage()]);
    }
}

/**
 * 處理儲存故事樣板的請求 (V2.0 - 支援中文檔名)
 */
function handle_save_story_template_request() {
    $input = json_decode(file_get_contents('php://input'), true);
    $templateName = $input['name'] ?? '';
    $configData = $input['config'] ?? null;

    if (empty($templateName) || empty($configData)) {
        send_json_response(['success' => false, 'message' => '缺少樣板名稱或設定數據。']);
        return;
    }

    // 【核心修正】更新過濾規則以支援中文檔名
    // 1. 移除所有"非" (^) Unicode字母(\p{L})、數字(\p{N})、空格(\s)、底線、連字號的字元
    //    '/u' 修飾符是讓正規表達式支援 UTF-8 (中文) 的關鍵
    $safeFilenameBase = preg_replace('/[^\p{L}\p{N}\s_\-]/u', '', $templateName);

    // 2. 將一個或多個連續的空格替換成一個底線，讓檔名更美觀
    $safeFilenameBase = preg_replace('/\s+/', '_', $safeFilenameBase);

    // 3. 檢查過濾後檔名是否為空
    if (empty($safeFilenameBase)) {
        send_json_response(['success' => false, 'message' => '樣板名稱無效，請使用包含字母或數字的名稱。']);
        return;
    }

    $safeFilename = $safeFilenameBase . '.json';
    $filePath = __DIR__ . '/story_templates/' . $safeFilename;

    try {
        // 將設定檔本身和元數據一起儲存
        $dataToSave = [
            'name' => $templateName, // 這裡儲存的是原始的、未經修改的中文名稱
            'createdAt' => date('Y-m-d H:i:s'),
            'config' => $configData
        ];

        // 使用 LOCK_EX 確保檔案寫入的原子性，防止多人同時操作時檔案損壞
        if (file_put_contents($filePath, json_encode($dataToSave, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new Exception("無法寫入檔案，請檢查伺服器權限。");
        }

        send_json_response(['success' => true, 'message' => '樣板已成功儲存！']);
    } catch (Exception $e) {
        http_response_code(500);
        send_json_response(['success' => false, 'message' => '儲存樣板時發生錯誤: ' . $e->getMessage()]);
    }
}/**
 * 處理獲取故事樣板列表的請求
 */
function handle_get_story_templates_request() {
    $templatesDir = __DIR__ . '/story_templates';
    $files = glob($templatesDir . '/*.json');
    $templates = [];

    foreach ($files as $file) {
        $content = json_decode(file_get_contents($file), true);
        if (isset($content['name'])) {
            $templates[] = [
                'id' => basename($file),
                'name' => $content['name'],
                'createdAt' => $content['createdAt'] ?? 'N/A'
            ];
        }
    }

    // 依建立時間排序
    usort($templates, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));

    send_json_response(['success' => true, 'templates' => $templates]);
}/**
 * 處理載入特定故事樣板的請求
 */
function handle_load_story_template_request() {
    $templateId = basename($_GET['id'] ?? '');
    if (empty($templateId)) {
        send_json_response(['success' => false, 'message' => '缺少樣板 ID。']);
        return;
    }

    $filePath = __DIR__ . '/story_templates/' . $templateId;

    if (file_exists($filePath)) {
        $content = json_decode(file_get_contents($filePath), true);
        if (isset($content['config'])) {
            send_json_response(['success' => true, 'config' => $content['config']]);
        } else {
            send_json_response(['success' => false, 'message' => '樣板檔案格式不正確。']);
        }
    } else {
        http_response_code(404);
        send_json_response(['success' => false, 'message' => '找不到指定的樣板檔案。']);
    }
}/**
 * 處理清空所有歷史記錄的請求
 * [修正後版本]：增加資料表存在性檢查，提升穩健性
 */
function handle_clear_all_history_request($db) {
    try {
        // 使用資料庫交易，確保所有刪除操作要麼全部成功，要麼全部失敗
        $db->beginTransaction();

        // 檢查並刪除 report_components 資料
        $stmt_check_components = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = 'report_components'");
        if ($stmt_check_components->fetch()) {
            $db->exec("DELETE FROM report_components");
            $db->exec("DELETE FROM sqlite_sequence WHERE name = 'report_components'");
        }

        // 檢查並刪除 reports 資料
        $stmt_check_reports = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = 'reports'");
        if ($stmt_check_reports->fetch()) {
            $db->exec("DELETE FROM reports");
            $db->exec("DELETE FROM sqlite_sequence WHERE name = 'reports'");
        }

        // 檢查並刪除 projects 資料
        $stmt_check_projects = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = 'projects'");
        if ($stmt_check_projects->fetch()) {
            $db->exec("DELETE FROM projects");
            $db->exec("DELETE FROM sqlite_sequence WHERE name = 'projects'");
        }

        $db->commit();

        // 刪除 results 資料夾中的所有 JSON 報告檔案
        $files = glob(RESULTS_DIR . '/*.json'); // 取得所有 .json 檔案
        foreach($files as $file){
            if(is_file($file)) {
                unlink($file); // 刪除檔案
            }
        }

        send_json_response(['success' => true, 'message' => '所有歷史記錄已成功清除。']);

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        send_json_response(['success' => false, 'message' => '清除歷史記錄時發生錯誤: ' . $e->getMessage()]);
    }
}


// --- 其他後端函式 ---
// 【全新】通用樣板渲染函式
function render_report_template($jsonData, $template_file) {
    // 準備要傳遞給樣板的數據
    $safeJsonData = json_encode(json_decode($jsonData));
    $template_path = __DIR__ . '/report_templates/' . $template_file;

    // 檢查樣板檔案是否存在
    if (!file_exists($template_path)) {
        http_response_code(404);
        die("伺服器錯誤：找不到指定的報告樣板檔案 '{$template_file}'。");
    }

    // 使用輸出緩衝來載入並渲染樣板
    ob_start();
    include $template_path;
    echo ob_get_clean();
}


function render_detailed_report_page($jsonData) {
    $safeJsonData = json_encode(json_decode($jsonData));
    $template_path = __DIR__ . '/detailed_report_template.php';
    if (!file_exists($template_path)) {
        http_response_code(500);
        die("伺服器錯誤：詳細報告的樣板檔案 'detailed_report_template.php' 不存在。");
    }
    ob_start();
    include $template_path;
    echo ob_get_clean();
}

function render_embed_page($jsonData) {
    $safeJsonData = json_encode(json_decode($jsonData));
    $template_path = __DIR__ . '/embed_template.php';
    if (!file_exists($template_path)) {
        http_response_code(500);
        die("伺服器錯誤：內嵌報告的樣板檔案 'embed_template.php' 不存在。");
    }
    ob_start();
    include $template_path;
    echo ob_get_clean();
}

function send_json_response($data) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

/**
 * 根據傳入的 SDG 編號陣列，產生對應的圖示 HTML
 * @param array $sdg_numbers - 一個包含 SDG 編號的陣列 (例如 [12, 13])
 * @return string - 完整的 HTML 字串
 */
function generateSdgIconsHtml(array $sdg_numbers): string
{
    if (empty($sdg_numbers)) {
        return '';
    }

    $sdg_titles = [
        1 => "SDG 1: 終結貧窮", 2 => "SDG 2: 消除飢餓", 3 => "SDG 3: 健康與福祉",
        4 => "SDG 4: 優質教育", 5 => "SDG 5: 性別平等", 6 => "SDG 6: 潔淨水與衛生",
        7 => "SDG 7: 可負擔的潔淨能源", 8 => "SDG 8: 尊嚴就業與經濟發展", 9 => "SDG 9: 產業、創新與基礎設施",
        10 => "SDG 10: 減少不平等", 11 => "SDG 11: 永續城市與社區", 12 => "SDG 12: 責任消費與生產",
        13 => "SDG 13: 氣候行動", 14 => "SDG 14: 海洋生態", 15 => "SDG 15: 陸域生態",
        16 => "SDG 16: 和平、正義與健全制度", 17 => "SDG 17: 夥伴關係"
    ];

    $html = '<div class="sdg-icons-container ms-auto d-flex align-items-center">';
    foreach ($sdg_numbers as $num) {
        $formatted_num = sprintf('%02d', $num);
        $title = htmlspecialchars($sdg_titles[$num] ?? "SDG {$num}", ENT_QUOTES, 'UTF-8');

        $html .= "<img src='assets/img/SDGs_{$formatted_num}.png' alt='SDG {$num}' class='sdg-icon' data-bs-toggle='tooltip' data-bs-placement='top' data-bs-title='{$title}'>";
    }
    $html .= '</div>';
    return $html;
}

// --- 請求路由 (完整版) ---
if (isset($_GET['action'])) {
    $db = initialize_database();
    $user_id = $_SESSION['user_id'];

    if ($_GET['action'] === 'show_tnfd_war_room') {
        // 這個路由只負責載入樣板檔案，所有數據處理都在樣板內部完成
        require_once __DIR__ . '/tnfd_page_template.php';
        exit; // 結束執行，不繼續渲染主儀表板
    }

    switch ($_GET['action']) {
        case 'get_all_processes':
            header('Content-Type: application/json; charset=utf-8');
            try {
                // 這段邏輯與 manage_materials.php 完全相同，確保數據結構一致
                $stmt_procs = $db->query("SELECT * FROM processes ORDER BY category, name ASC");
                $all_procs = $stmt_procs->fetchAll(PDO::FETCH_ASSOC);

                $stmt_opts = $db->query("SELECT process_key, id, option_key, option_name FROM process_options");
                $options_by_proc_key = $stmt_opts->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

                $stmt_mults = $db->query("SELECT option_id, item_key, item_name, energy_multiplier FROM process_option_multipliers");
                $multipliers_by_opt_id = $stmt_mults->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

                $final_processes = [];
                foreach ($all_procs as $proc) {
                    $proc_key = $proc['process_key'];
                    $proc['options'] = [];

                    if (isset($options_by_proc_key[$proc_key])) {
                        foreach ($options_by_proc_key[$proc_key] as $opt) {
                            $option_id = $opt['id'];
                            $option_key = $opt['option_key'];
                            $choices = $multipliers_by_opt_id[$option_id] ?? [];
                            $formatted_choices = array_map(function($choice) {
                                return [
                                    'key' => $choice['item_key'],
                                    'name' => $choice['item_name'],
                                    'energy_multiplier' => (float)$choice['energy_multiplier']
                                ];
                            }, $choices);
                            $proc['options'][$option_key] = [
                                'name' => $opt['option_name'],
                                'choices' => $formatted_choices
                            ];
                        }
                    }
                    $final_processes[] = $proc;
                }
                // 將完整的、巢狀的製程資料回傳給前端
                send_json_response(['success' => true, 'data' => $final_processes]);
            } catch (Exception $e) {
                http_response_code(500);
                send_json_response(['success' => false, 'message' => '獲取製程數據時發生錯誤: ' . $e->getMessage()]);
            }
            break;
        // 【核心新增】API: 建立組織 (從 index.php 快速建立)
        case 'create_organization':
            $input = json_decode(file_get_contents('php://input'), true);
            $name = trim($input['name'] ?? '');
            if ($name) {
                // 驗證組織名稱是否已存在，避免重複
                $stmt_check = $db->prepare("SELECT id FROM organizations WHERE name = ? AND user_id = ?");
                $stmt_check->execute([$name, $user_id]);
                if ($stmt_check->fetch()) {
                    send_json_response(['success' => false, 'message' => "組織名稱 '{$name}' 已存在。"]);
                    break;
                }

                // 只插入最核心的 name 和 user_id
                $stmt = $db->prepare("INSERT INTO organizations (user_id, name) VALUES (?, ?)");
                $stmt->execute([$user_id, $name]);
                $org_id = $db->lastInsertId();
                send_json_response(['success' => true, 'id' => $org_id, 'name' => $name, 'message' => '組織已建立。']);
            } else {
                send_json_response(['success' => false, 'message' => '組織名稱不可為空。']);
            }
            break;

        // 【核心新增】API: 為舊專案指派組織 (遷移用)
        case 'assign_project_organization':
            $input = json_decode(file_get_contents('php://input'), true);
            $project_id = (int)($input['project_id'] ?? 0);
            $organization_id = (int)($input['organization_id'] ?? 0);

            if (!$project_id || !$organization_id) {
                send_json_response(['success' => false, 'message' => '缺少必要參數。']);
                break;
            }

            // 安全驗證：確保專案和目標組織都屬於該使用者
            $stmt_proj_check = $db->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
            $stmt_proj_check->execute([$project_id, $user_id]);
            if (!$stmt_proj_check->fetch()) {
                send_json_response(['success' => false, 'message' => '找不到專案或權限不足。']);
                break;
            }

            $stmt_org_check = $db->prepare("SELECT id FROM organizations WHERE id = ? AND user_id = ?");
            $stmt_org_check->execute([$organization_id, $user_id]);
            if (!$stmt_org_check->fetch()) {
                send_json_response(['success' => false, 'message' => '找不到目標組織或權限不足。']);
                break;
            }

            // 執行更新
            $stmt_update = $db->prepare("UPDATE projects SET organization_id = ? WHERE id = ?");
            $stmt_update->execute([$organization_id, $project_id]);

            send_json_response(['success' => true, 'message' => '專案已成功指派！']);
            break;
        case 'get_reference_data':
            try {
                // 1. 獲取所有物料的完整數據
                $all_materials_stmt = $db->query("SELECT * FROM materials ORDER BY name ASC");
                $all_materials = $all_materials_stmt->fetchAll(PDO::FETCH_ASSOC);

                // 2. 獲取所有國家的座標與名稱
                $countries_db_content = file_exists(__DIR__ . '/countries_db.json') ? file_get_contents(__DIR__ . '/countries_db.json') : '[]';
                $countries_db = json_decode($countries_db_content, true);

                // 3. 【新增】獲取完整的 TNFD 全球風險資料庫
                $tnfd_db_content = file_exists(__DIR__ . '/biodiversity_risks_expert_zh_v2.json') ? file_get_contents(__DIR__ . '/biodiversity_risks_expert_zh_v2.json') : '{}';
                $tnfd_db = json_decode($tnfd_db_content, true);

                // 4. 打包成一個 JSON 回應
                send_json_response([
                    'success' => true,
                    'materials' => array_column($all_materials, null, 'key'), // 以 key 為索引，方便JS查找
                    'countries' => array_column($countries_db, null, 'en'),    // 以 en code 為索引
                    'tnfdRiskDb' => $tnfd_db // 將完整的風險資料庫傳給前端
                ]);

            } catch (Exception $e) {
                http_response_code(500);
                send_json_response(['success' => false, 'message' => '獲取參考數據時發生錯誤: ' . $e->getMessage()]);
            }
            break;
        case 'get_tnfd_layer':
            require_once __DIR__ . '/tnfd_api_handler.php';
            break;

        case 'generate_communication_content':
            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $reportData = $payload['reportData'] ?? null;
                $storyArchetype = $payload['storyArchetype'] ?? ['name' => '智者'];

                if (empty($reportData)) {
                    send_json_response(['success' => false, 'message' => '缺少報告數據，無法生成文案。']);
                    return;
                }
                if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY) || GEMINI_API_KEY === '在這裡貼上您從Google AI Studio複製的API金鑰') {
                    send_json_response(['success' => false, 'message' => '尚未設定有效的 Gemini API 金鑰。']);
                    return;
                }
                // 檢查 get_ai_prompt_for_comms 函式是否存在
                if (!function_exists('get_ai_prompt_for_comms')) {
                    send_json_response(['success' => false, 'message' => '後端錯誤：找不到必要的 get_ai_prompt_for_comms 函式。']);
                    return;
                }

                $prompt = get_ai_prompt_for_comms($reportData, $storyArchetype);

                $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL_NAME . ':generateContent?key=' . GEMINI_API_KEY;
                $post_data = [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 1024]
                ];

                $ch = curl_init($api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);

                if ($curl_error) {
                    send_json_response(['success' => false, 'message' => "伺服器底層通訊錯誤 (cURL): " . $curl_error]);
                    return;
                }

                $result = json_decode($response, true);

                if ($http_code !== 200 || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    $error_message = $result['error']['message'] ?? '未知的API錯誤，請檢查API金鑰或帳戶額度。';
                    send_json_response(['success' => false, 'message' => "Gemini API 服務回傳錯誤 (HTTP Code: {$http_code}): " . $error_message]);
                    return;
                }

                send_json_response(['success' => true, 'content' => $result['candidates'][0]['content']['parts'][0]['text']]);

            } catch (Exception $e) {
                // 捕捉任何未預期的 PHP 錯誤
                send_json_response(['success' => false, 'message' => '後端 PHP 執行時發生例外錯誤: ' . $e->getMessage()]);
            }
            break;

        case 'get_dpp_signature':
            $viewId = basename($_GET['view_id'] ?? '');
            if (empty($viewId)) {
                send_json_response(['success' => false, 'message' => '缺少報告 ID。']);
                break;
            }

            // 安全驗證：確保報告屬於目前使用者
            $stmt_check_owner = $db->prepare("SELECT COUNT(id) FROM reports WHERE view_id = ? AND user_id = ?");
            $stmt_check_owner->execute([$viewId, $user_id]);
            if ($stmt_check_owner->fetchColumn() == 0) {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足。']);
                break;
            }

            $filePath = RESULTS_DIR . '/' . $viewId . '.json';
            if (file_exists($filePath)) {
                $reportData = json_decode(file_get_contents($filePath), true);
                $signature = generateDppSignature($reportData);
                send_json_response(['success' => true, 'signature' => $signature]);
            } else {
                http_response_code(404);
                send_json_response(['success' => false, 'message' => '找不到報告檔案。']);
            }
            break;
        // ⭐ START: 新增物料/製程回資料庫的 API 端點
        case 'add_new_component':
            if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'superadmin') {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足，無法新增至資料庫。']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $type = $input['type'] ?? '';
            $data = $input['data'] ?? [];

            if (empty($type) || empty($data)) {
                send_json_response(['success' => false, 'message' => '缺少類型或數據。']);
                break;
            }

            try {
                if ($type === 'material') {
                    // 1. 寫入資料庫
                    $sql = "INSERT INTO materials (key, name, unit, category) VALUES (:key, :name, 'kg', :category)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([':key' => $data['key'], ':name' => $data['name'], ':category' => $data['category']]);

                    // 2. 同步寫入 JSON 檔案
                    $json_file_path = __DIR__ . '/materials_data.json';
                    $materials_from_json = json_decode(file_get_contents($json_file_path), true);
                    $new_material_for_json = [
                        'key' => $data['key'], 'name' => $data['name'], 'unit' => 'kg', 'category' => $data['category'],
                        // 提供合理的預設值
                        'virgin_co2e_kg' => 1, 'recycled_co2e_kg' => 0.5, 'cost_per_kg' => 1
                    ];
                    $materials_from_json[] = $new_material_for_json;
                    file_put_contents($json_file_path, json_encode(array_values($materials_from_json), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

                    send_json_response(['success' => true, 'message' => '新物料已新增。', 'component' => $new_material_for_json]);

                } elseif ($type === 'process') {
                    // 1. 寫入資料庫
                    $sql = "INSERT INTO processes (process_key, name, unit, category, energy_consumption_kwh) VALUES (:key, :name, 'kg', :category, 0.1)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([':key' => $data['key'], ':name' => $data['name'], ':category' => $data['category']]);

                    // 2. 同步寫入 JSON 檔案
                    $json_file_path = __DIR__ . '/processes_data.json'; // 假設您的製程檔名
                    $processes_from_json = json_decode(file_get_contents($json_file_path), true);
                    $new_process_for_json = [
                        'process_key' => $data['key'], 'name' => $data['name'], 'category' => $data['category'],
                        'unit' => 'kg', 'energy_consumption_kwh' => 0.1, 'options' => new stdClass()
                    ];
                    $processes_from_json[] = $new_process_for_json;
                    file_put_contents($json_file_path, json_encode(array_values($processes_from_json), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

                    send_json_response(['success' => true, 'message' => '新製程已新增。', 'component' => $new_process_for_json]);
                }
            } catch (Exception $e) {
                send_json_response(['success' => false, 'message' => '新增失敗: ' . $e->getMessage()]);
            }
            break;
        // ⭐ START: V3.1 AI 視覺辨識 API (提案模式)
        case 'identify_from_image':
            $input = json_decode(file_get_contents('php://input'), true);
            $imageData = $input['image_data'] ?? null;
            if (!$imageData || !preg_match('/^data:image\/(jpeg|png|gif);base64,/', $imageData, $matches)) {
                send_json_response(['success' => false, 'message' => '無效的圖片格式。']); break;
            }
            $base64Image = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $imageData));
            $mimeType = strtolower($matches[1]);
            $prompt = "
            角色：你是一位頂級的材料科學家與LCA專家，擅長將成品拆解為最基礎的「原材料」與「核心製程」。
            任務：根據提供的圖片，辨識出主要物件，並將其拆解為最接近源頭的「原材料」列表。你的目標不是描述產品的零件，而是構成這些零件的基礎工業材料。
            
            重要原則：
            - **精準到原材料層級**：例如，看到一個塑膠瓶，你應辨識為「PET」或「HDPE」，而非「塑膠瓶」。看到金屬外殼，應辨識為「鋁合金」或「不鏽鋼」，而非「金屬外殼」。
            - **參考範例**: 你的回答應盡量符合以下分類，例如「通用塑膠」、「工程塑膠」、「黑色金屬」、「非鐵金屬」、「植物基紡織品」。

            格式要求：
            1.  **語言**: 你的回應中，所有 `name` 欄位的值都必須是「**繁體中文**」。
            2.  你的回應必須是 RFC8259 標準的 JSON 格式，不包含任何額外說明文字。
            3.  JSON 根物件必須包含 `objectName` (字串), `materials` (陣列), `processes` (陣列)。
            4.  `materials` 陣列中的每個物件需包含 `name` (字串, 例如 '聚對苯二甲酸乙二酯 (PET)' 或 '不鏽鋼 304') 和 `estimated_weight_pct` (數字, 重量百分比)。
            5.  `processes` 陣列中的每個物件需包含 `name` (字串, 例如 '射出成型' 或 '金屬沖壓')。
            ";

            $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . GEMINI_API_KEY;
            $post_data = ['contents' => [['parts' => [['text' => $prompt],['inline_data' => ['mime_type' => 'image/' . $mimeType,'data' => base64_encode($base64Image)]]]]],'generationConfig' => ['response_mime_type' => 'application/json']];
            $ch = curl_init($api_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); $response = curl_exec($ch); curl_close($ch);
            $result = json_decode($response, true);

            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $ai_json_response = json_decode($result['candidates'][0]['content']['parts'][0]['text'], true);
                if (json_last_error() === JSON_ERROR_NONE && isset($ai_json_response['materials'])) {
                    send_json_response(['success' => true, 'data' => $ai_json_response]);
                } else { send_json_response(['success' => false, 'message' => 'AI 回應的並非有效的BOM JSON格式。']); }
            } else { send_json_response(['success' => false, 'message' => 'AI 服務無回應或發生錯誤。', 'debug' => $result]); }
            break;
        // ⭐ END: AI 視覺辨識 API

        // ⭐ START: AI 智慧擴充 ESG 數據的 API 端點 (V3.2 - 欄位驗證修正版)
        case 'add_new_component_with_ai_enrichment':
            if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'superadmin') {
                http_response_code(403); send_json_response(['success' => false, 'message' => '權限不足，無法新增至資料庫。']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $type = $input['type'] ?? ''; $data = $input['data'] ?? [];
            if (empty($type) || empty($data['key']) || empty($data['name']) || empty($data['category'])) {
                send_json_response(['success' => false, 'message' => '缺少類型、KEY、名稱或分類。']); break;
            }
            try {
                $db->beginTransaction();
                if ($type === 'material') {
                    // 步驟 1: 先插入基礎資料 (這部分不變)
                    $sql = "INSERT INTO materials (key, name, unit, category) VALUES (:key, :name, 'kg', :category)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([':key' => $data['key'], ':name' => $data['name'], ':category' => $data['category']]);

                    // 步驟 2: 呼叫 AI 擴充數據 (這部分不變)
                    $esg_prompt = "
                    角色: 你是一位頂級的材料科學與 ESG 數據分析師，擁有 Ecoinvent 和 GaBi 等LCA資料庫的知識。
                    任務: 根據下方提供的材料基本資訊，為其產生一套合理、完整的 ESG 相關數據。
                    材料資訊:
                    - name: " . $data['name'] . "
                    - category: " . $data['category'] . "
                    格式要求:
                    1. 你的回應必須是 RFC8259 標準的 JSON 格式，不包含任何額外說明文字。
                    2. JSON 物件必須嚴格包含以下所有 key，並根據你的專業知識填入最可能、最合理的預估值：
                       - virgin_co2e_kg: float
                       - recycled_co2e_kg: float
                       - virgin_energy_mj_kg: float
                       - recycled_energy_mj_kg: float
                       - virgin_water_l_kg: float
                       - recycled_water_l_kg: float
                       - cost_per_kg: float
                       - social_risk_score: int (0-100)
                       - governance_risk_score: int (0-100)
                       - labor_practices_risk_score: int (0-100)
                       - health_safety_risk_score: int (0-100)
                       - business_ethics_risk_score: int (0-100)
                       - transparency_risk_score: int (0-100)
                       - is_high_child_labor_risk: int (0 or 1)
                       - is_high_forced_labor_risk: int (0 or 1)
                       - country_of_origin: string (JSON array format, e.g., '[{\"country\":\"CN\",\"percentage\":60}]')
                       - biodiversity_risks: string (JSON array format from ['DEFORESTATION', 'HABITAT_LOSS', 'WATER_POLLUTION'])
                       - is_from_high_deforestation_risk_area: int (0 or 1)
                       - cbam_category: string (e.g., 'STEEL', 'ALUMINUM', or null)
                       - recyclability_rate_pct: float (0-100)
                       - eol_recycle_credit_co2e: float (usually negative)
                    ";
                    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL_NAME . ':generateContent?key=' . GEMINI_API_KEY;
                    $post_data = ['contents' => [['parts' => [['text' => $esg_prompt]]]], 'generationConfig' => ['response_mime_type' => 'application/json']];
                    $ch = curl_init($api_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); $response = curl_exec($ch); curl_close($ch);

                    $ai_esg_data = [];
                    $result = json_decode($response, true);
                    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                        $ai_esg_data = json_decode($result['candidates'][0]['content']['parts'][0]['text'], true);
                    }

                    // ▼▼▼ 【核心修正】在這裡加入「白名單」防護層 ▼▼▼
                    if (!empty($ai_esg_data) && is_array($ai_esg_data)) {
                        $allowed_material_columns = [
                            'virgin_co2e_kg', 'recycled_co2e_kg', 'virgin_energy_mj_kg', 'recycled_energy_mj_kg',
                            'virgin_water_l_kg', 'recycled_water_l_kg', 'cost_per_kg', 'social_risk_score',
                            'governance_risk_score', 'labor_practices_risk_score', 'health_safety_risk_score',
                            'business_ethics_risk_score', 'transparency_risk_score', 'is_high_child_labor_risk',
                            'is_high_forced_labor_risk', 'country_of_origin', 'biodiversity_risks',
                            'is_from_high_deforestation_risk_area', 'cbam_category', 'recyclability_rate_pct',
                            'eol_recycle_credit_co2e'
                        ];

                        $update_params = [':key' => $data['key']];
                        $update_clauses = [];

                        foreach ($ai_esg_data as $field => $value) {
                            // 只有在 AI 回傳的欄位存在於我們的白名單中時，才將其加入 SQL 語句
                            if (in_array($field, $allowed_material_columns)) {
                                $update_clauses[] = "{$field} = :{$field}";
                                $update_params[":{$field}"] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
                            }
                        }

                        if (!empty($update_clauses)) {
                            $update_sql = "UPDATE materials SET " . implode(', ', $update_clauses) . " WHERE key = :key";
                            $stmt_update = $db->prepare($update_sql);
                            $stmt_update->execute($update_params);
                        }
                    }
                    // ▲▲▲ 【核心修正】結束 ▲▲▲

                    // 步驟 4: 同步更新 JSON 檔案 (這部分不變)
                    $json_file_path = __DIR__ . '/materials_data.json';
                    $materials_from_json = json_decode(file_get_contents($json_file_path), true);
                    $new_material_for_json = array_merge(['key' => $data['key'], 'name' => $data['name'], 'unit' => 'kg', 'category' => $data['category']], $ai_esg_data);
                    if(is_string($new_material_for_json['country_of_origin'] ?? null)) $new_material_for_json['country_of_origin'] = json_decode($new_material_for_json['country_of_origin'], true);
                    if(is_string($new_material_for_json['biodiversity_risks'] ?? null)) $new_material_for_json['biodiversity_risks'] = json_decode($new_material_for_json['biodiversity_risks'], true);
                    $materials_from_json[] = $new_material_for_json;
                    file_put_contents($json_file_path, json_encode(array_values($materials_from_json), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

                    $db->commit();
                    send_json_response(['success' => true, 'message' => '新物料已新增，並由 AI 智慧擴充 ESG 數據！', 'component' => $new_material_for_json]);

                } elseif ($type === 'process') {
                    // 製程部分的邏輯相對安全，因為它不是動態產生 SQL，所以維持原樣即可
                    $sql = "INSERT INTO processes (process_key, name, category, unit) VALUES (:key, :name, :category, 'kg')";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([':key' => $data['key'], ':name' => $data['name'], ':category' => $data['category']]);

                    $process_prompt = "
                    角色: 你是一位頂級的製造工程與 LCA 數據分析師。
                    任務: 根據下方提供的製程基本資訊，為其產生一套合理的、符合特定 JSON 格式的技術數據。
                    製程資訊:
                    - name: " . $data['name'] . "
                    - category: " . $data['category'] . "
                    格式要求:
                    1. 你的回應必須是 RFC8259 標準的 JSON 格式，不包含任何額外說明文字。
                    2. JSON 根物件必須包含 `base_energy_kwh` (float), `unit` (string, e.g., 'kg', 'm2', 'piece'), `source_info` (object), `options` (object)。
                    3. `source_info` 物件需包含 `name` (string, e.g., 'Ecoinvent'), `version` (string, e.g., '3.8'), `year` (int), `region` (string, e.g., 'Global')。
                    4. `options` 物件是選填的，但若提供，其結構必須是 `{ \"option_key\": { \"name\": \"中文名稱\", \"choices\": [{\"key\": \"CHOICE_KEY\", \"name\": \"選項中文名\", \"energy_multiplier\": float}] } }`。請至少提供 1-2 個最常見的選項維度。
                    ";

                    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL_NAME . ':generateContent?key=' . GEMINI_API_KEY;
                    $post_data = ['contents' => [['parts' => [['text' => $process_prompt]]]], 'generationConfig' => ['response_mime_type' => 'application/json']];
                    $ch = curl_init($api_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); $response = curl_exec($ch); curl_close($ch);

                    $ai_process_data = [];
                    $result = json_decode($response, true);
                    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                        $ai_process_data = json_decode($result['candidates'][0]['content']['parts'][0]['text'], true);
                    }

                    $source_str = 'N/A';
                    if (!empty($ai_process_data['source_info'])) {
                        $source_parts = array_filter([$ai_process_data['source_info']['name'] ?? '', $ai_process_data['source_info']['version'] ?? '', $ai_process_data['source_info']['year'] ?? '']);
                        $source_str = implode(', ', $source_parts);
                    }

                    $stmt_update = $db->prepare("UPDATE processes SET energy_consumption_kwh = ?, unit = ?, data_source = ? WHERE process_key = ?");
                    $stmt_update->execute([(float)($ai_process_data['base_energy_kwh'] ?? 0.1), $ai_process_data['unit'] ?? 'kg', $source_str, $data['key']]);

                    if (!empty($ai_process_data['options']) && is_array($ai_process_data['options'])) {
                        $stmt_option = $db->prepare("INSERT INTO process_options (process_key, option_key, option_name) VALUES (?, ?, ?)");
                        $stmt_multiplier = $db->prepare("INSERT INTO process_option_multipliers (option_id, item_key, item_name, energy_multiplier) VALUES (?, ?, ?, ?)");
                        foreach ($ai_process_data['options'] as $option_key => $option_details) {
                            // 【健壯性修正】確保 option_details 是陣列且包含必要 key
                            if (!is_array($option_details) || !isset($option_details['name']) || !isset($option_details['choices'])) continue;

                            $stmt_option->execute([$data['key'], $option_key, $option_details['name']]);
                            $option_id = $db->lastInsertId();
                            if (!empty($option_details['choices']) && is_array($option_details['choices'])) {
                                foreach ($option_details['choices'] as $item) {
                                    $stmt_multiplier->execute([$option_id, $item['key'], $item['name'], (float)($item['energy_multiplier'] ?? 1.0)]);
                                }
                            }
                        }
                    }

                    $json_file_path = __DIR__ . '/processes_db/ai_added_processes.json'; // 建議寫入獨立檔案
                    $processes_from_json = file_exists($json_file_path) ? json_decode(file_get_contents($json_file_path), true) : [];
                    $new_process_for_json = array_merge($data, $ai_process_data);
                    $new_process_for_json['process_key'] = $data['key'];
                    $processes_from_json[] = $new_process_for_json;
                    file_put_contents($json_file_path, json_encode(array_values($processes_from_json), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

                    $db->commit();
                    send_json_response(['success' => true, 'message' => '新製程已新增，並由 AI 智慧擴充數據！', 'component' => $new_process_for_json]);
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                send_json_response(['success' => false, 'message' => '新增失敗: ' . $e->getMessage()]);
            }
            break;
        // ⭐ END: AI 智慧擴充 API 端點
        case 'chat_follow_up':
            handle_chat_follow_up_request();
            break;
        case 'generate_narrative':
            handle_generate_narrative_request();
            break;
        case 'comparison_carousel':
            $viewIds = $_GET['ids'] ?? [];
            if (empty($viewIds) || !is_array($viewIds)) {
                die('錯誤：請至少選擇兩份報告。');
            }

            // 【效能優化】一次性查詢所有 view_id，驗證所有權，避免在迴圈中查詢資料庫
            $cleanViewIds = array_map('basename', $viewIds);
            $placeholders = implode(',', array_fill(0, count($cleanViewIds), '?'));
            $sql = "SELECT view_id FROM reports WHERE view_id IN ($placeholders) AND user_id = ?";
            $stmt = $db->prepare($sql);

            $params = $cleanViewIds;
            $params[] = $user_id; // 將 user_id 加入到參數陣列的末尾
            $stmt->execute($params);

            // 獲取所有屬於該使用者且在請求列表中的 view_id
            $ownedViewIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $reportsData = [];
            // 【效能優化】現在的迴圈只處理檔案讀取，不再有資料庫操作
            foreach ($ownedViewIds as $viewId) {
                $filePath = RESULTS_DIR . '/' . $viewId . '.json';
                if (file_exists($filePath)) {
                    $reportsData[] = json_decode(file_get_contents($filePath), true);
                }
            }

            if (count($reportsData) < 2) {
                die('錯誤：有效的報告數量不足以進行比較。');
            }

            // 將多份報告的數據打包成一個 JSON 字串
            $safeJsonData = json_encode($reportsData);

            // 載入並執行新的比較輪播樣板
            include 'comparison_carousel_template.php';
            exit;
            break;
        case 'get_project_history':
            $projectId = (int)($_GET['project_id'] ?? 0);
            if ($projectId > 0) {
                $stmt = $db->prepare("
            SELECT version_name, total_co2e_kg, created_at 
            FROM reports 
            WHERE project_id = ? AND user_id = ? 
            ORDER BY created_at ASC
        ");
                $stmt->execute([$projectId, $user_id]);
                send_json_response(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } else {
                send_json_response(['success' => false, 'message' => '無效的專案 ID。']);
            }
            break;
        case 'save_story_template':
            handle_save_story_template_request();
            break;
        case 'get_story_templates':
            handle_get_story_templates_request();
            break;
        case 'load_story_template':
            handle_load_story_template_request();
            break;
        case 'get_all_materials':
            if ($_SESSION['username'] !== 'superadmin') {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足。']);
                break;
            }
            $stmt = $db->query("SELECT * FROM materials ORDER BY category, name ASC");
            send_json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'inline_update_material':
            if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'superadmin') {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足。']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $key_to_update = $input['original_key'] ?? ($input['key'] ?? null);
            if (!$key_to_update) {
                send_json_response(['success' => false, 'message' => '缺少物料 KEY。']);
                break;
            }
            try {
                // ✨ 核心修正 START: 正確處理所有陣列與JSON字串 ✨
                $fields_that_are_arrays = [
                    'known_risks', 'certifications', 'identified_risks', 'positive_attributes',
                    'biodiversity_risks', 'positive_actions', 'recycled_content_certification',
                    'impact_drivers', 'ecosystem_service_dependencies'
                ];

                foreach ($fields_that_are_arrays as $field) {
                    if (isset($input[$field]) && is_array($input[$field])) {
                        $input[$field] = json_encode($input[$field], JSON_UNESCAPED_UNICODE);
                    }
                }

                // ✨ 刪除舊的、錯誤的 country_of_origin 處理邏輯
                //    因為前端 modal 已經送來了正確的 JSON 字串，所以後端無需再做任何處理。

                unset($input['original_key']);
                $set_clauses = [];
                foreach ($input as $field => $value) {
                    $set_clauses[] = "`" . str_replace("`", "``", $field) . "` = :{$field}";
                }

                if (empty($set_clauses)) {
                    send_json_response(['success' => false, 'message' => '沒有要更新的欄位。']);
                    break;
                }

                $sql = "UPDATE materials SET " . implode(', ', $set_clauses) . " WHERE key = :key_to_update";
                $stmt = $db->prepare($sql);

                $input['key_to_update'] = $key_to_update;

                if ($stmt->execute($input)) {
                    // (同步更新 JSON 檔案的邏輯保持不變)
                    $json_file_path = __DIR__ . '/materials_data.json';
                    if (file_exists($json_file_path)) {
                        $materials_from_json = json_decode(file_get_contents($json_file_path), true);
                        $json_fields_for_decode = array_merge($fields_that_are_arrays, ['country_of_origin', 'sources']);
                        $numeric_fields = [
                            'virgin_co2e_kg', 'virgin_energy_mj_kg', 'virgin_water_l_kg', 'virgin_adp_kgsbe',
                            'recycled_co2e_kg', 'recycled_energy_mj_kg', 'recycled_water_l_kg', 'recycled_adp_kgsbe',
                            'eol_landfill_co2e', 'eol_incinerate_co2e', 'eol_recycle_credit_co2e', 'cost_per_kg',
                            'acidification_kg_so2e', 'eutrophication_kg_po4e', 'ozone_depletion_kg_cfc11e', 'photochemical_ozone_kg_nmvoce',
                            'social_risk_score', 'governance_risk_score', 'is_plastic_packaging', 'contains_svhc',
                            'biogenic_carbon_content_kg', 'is_critical_raw_material', 'recyclability_rate_pct',
                            'labor_practices_risk_score', 'health_safety_risk_score', 'is_high_child_labor_risk',
                            'business_ethics_risk_score', 'transparency_risk_score', 'is_from_sanctioned_country'
                        ];
                        $updated_materials = array_map(function($m) use ($key_to_update, $input, $numeric_fields, $json_fields_for_decode) {
                            if ($m['key'] === $key_to_update) {
                                foreach($input as $k => $v) {
                                    if($k == 'key_to_update') continue;

                                    if (in_array($k, $json_fields_for_decode) && is_string($v)) {
                                        $decoded = json_decode($v, true);
                                        $m[$k] = ($decoded !== null) ? $decoded : $v;
                                    } elseif (in_array($k, $numeric_fields)) {
                                        $m[$k] = is_numeric($v) ? floatval($v) : $v;
                                    } else {
                                        $m[$k] = $v;
                                    }
                                }
                            }
                            return $m;
                        }, $materials_from_json);

                        file_put_contents($json_file_path, json_encode($updated_materials, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                    }

                    $new_key = $input['key'] ?? $key_to_update;
                    $stmt_refetch = $db->prepare("SELECT * FROM materials WHERE key = ?");
                    $stmt_refetch->execute([$new_key]);
                    $updated_material = $stmt_refetch->fetch(PDO::FETCH_ASSOC);

                    send_json_response(['success' => true, 'message' => '物料更新成功！', 'updated_material' => $updated_material]);

                } else {
                    send_json_response(['success' => false, 'message' => '資料庫更新失敗。']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                send_json_response(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
            }
            break;

        case 'delete_material':
            if ($_SESSION['username'] !== 'superadmin') {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足。']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $key = $input['key'] ?? null;
            if (!$key) {
                send_json_response(['success' => false, 'message' => '缺少物料 KEY。']);
                break;
            }

            try {
                $db->beginTransaction();
                // 從資料庫刪除
                $stmt = $db->prepare("DELETE FROM materials WHERE key = ?");
                $stmt->execute([$key]);

                // 從 JSON 檔案刪除
                $json_file_path = __DIR__ . '/materials_data.json';
                $materials_from_json = json_decode(file_get_contents($json_file_path), true);
                $updated_materials = array_filter($materials_from_json, fn($m) => $m['key'] !== $key);
                file_put_contents($json_file_path, json_encode(array_values($updated_materials), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

                $db->commit();
                send_json_response(['success' => true, 'message' => "物料 '{$key}' 已成功刪除。"]);

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                http_response_code(500);
                send_json_response(['success' => false, 'message' => '刪除時發生錯誤: ' . $e->getMessage()]);
            }
            break;
        case 'add_material':
            if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'superadmin') {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足，無法新增物料。']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $key = $input['key'] ?? null;
            $name = $input['name'] ?? null;
            $unit = $input['unit'] ?? null;

            if (empty($key) || empty($name) || empty($unit)) {
                send_json_response(['success' => false, 'message' => '錯誤：KEY, 名稱, 單位為必填欄位。']);
                break;
            }

            $stmt_check = $db->prepare("SELECT id FROM materials WHERE key = ?");
            $stmt_check->execute([$key]);
            if ($stmt_check->fetch()) {
                send_json_response(['success' => false, 'message' => "錯誤：物料 KEY '{$key}' 已存在，請使用不同的 KEY。"]);
                break;
            }

            try {
                // ✨ 核心修正 START: 正確處理所有陣列與JSON字串 ✨
                $fields_to_encode = [
                    'known_risks', 'certifications', 'identified_risks', 'positive_attributes',
                    'biodiversity_risks', 'positive_actions', 'recycled_content_certification',
                    'impact_drivers', 'ecosystem_service_dependencies'
                ];

                $params_for_db = $input;
                foreach ($fields_to_encode as $field) {
                    // 檢查傳入的資料是否為陣列，如果是，則轉換為JSON字串
                    if (isset($params_for_db[$field]) && is_array($params_for_db[$field])) {
                        $params_for_db[$field] = json_encode($params_for_db[$field], JSON_UNESCAPED_UNICODE);
                    }
                }

                // 移除 original_key，因為這是新增操作
                unset($params_for_db['original_key']);

                $columns = array_keys($params_for_db);
                // 確保欄位名稱安全
                $safe_columns = array_map(function($c) { return "`" . str_replace("`", "``", $c) . "`"; }, $columns);
                $placeholders = implode(', ', array_map(fn($c) => ':' . $c, $columns));

                $sql = "INSERT INTO materials (".implode(', ', $safe_columns).") VALUES (". $placeholders .")";
                $stmt = $db->prepare($sql);

                $db->beginTransaction();
                $stmt->execute($params_for_db);

                // 同步更新 materials_data.json 檔案
                $json_file_path = __DIR__ . '/materials_data.json';
                $materials_from_json = json_decode(file_get_contents($json_file_path), true);

                // 準備要寫入 JSON 的資料 (將 JSON 字串還原為陣列)
                $new_material_for_json = $input;
                $json_fields_for_decode = array_merge($fields_to_encode, ['country_of_origin', 'sources']);
                foreach ($json_fields_for_decode as $field) {
                    if (isset($new_material_for_json[$field]) && is_string($new_material_for_json[$field])) {
                        $decoded = json_decode($new_material_for_json[$field], true);
                        // 只有在解碼成功時才替換
                        if ($decoded !== null) {
                            $new_material_for_json[$field] = $decoded;
                        }
                    }
                }

                $materials_from_json[] = $new_material_for_json;
                file_put_contents($json_file_path, json_encode(array_values($materials_from_json), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

                $db->commit();
                send_json_response(['success' => true, 'message' => '物料已成功新增！']);

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                http_response_code(500);
                send_json_response(['success' => false, 'message' => '儲存物料時發生錯誤: ' . $e->getMessage()]);
            }
            break;
        // ✨ 核心修正 END ✨

        case 'update_material':
            // 權限檢查：只有 superadmin 可以修改物料庫
            if ($_SESSION['username'] !== 'superadmin') {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足，無法修改物料庫。']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $key = $input['key'] ?? null;
            $field = $input['field'] ?? null;
            $value = $input['value'] ?? null;

            if (!$key || !$field) {
                http_response_code(400);
                send_json_response(['success' => false, 'message' => '無效的請求：缺少 key 或 field。']);
                break;
            }

            // 欄位白名單，防止惡意修改
            $allowed_fields = [
                'name', 'category', 'virgin_co2e_kg', 'virgin_energy_mj_kg', 'virgin_water_l_kg',
                'recycled_co2e_kg', 'recycled_energy_mj_kg', 'recycled_water_l_kg',
                'cost_per_kg', 'acidification_kg_so2e', 'eutrophication_kg_po4e',
                'ozone_depletion_kg_cfc11e', 'photochemical_ozone_kg_nmvoce',
                'virgin_adp_kgsbe', 'recycled_adp_kgsbe', 'data_source'
            ];

            if (!in_array($field, $allowed_fields)) {
                http_response_code(400);
                send_json_response(['success' => false, 'message' => '不允許修改的欄位。']);
                break;
            }

            try {
                // 使用白名單中的欄位名稱動態建立 SQL
                $sql = "UPDATE materials SET `{$field}` = ? WHERE key = ?";
                $stmt = $db->prepare($sql);

                if ($stmt->execute([$value, $key])) {
                    send_json_response(['success' => true, 'message' => '更新成功。']);
                } else {
                    send_json_response(['success' => false, 'message' => '資料庫更新失敗。']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                send_json_response(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
            }
            break;
        case 'get_settings':
            if ($_SESSION['username'] !== 'superadmin') {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足。']);
                break;
            }
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // ▼▼▼ 【核心修改】從 .env 檔案讀取密鑰並加入回傳資料中 ▼▼▼
            load_env(__DIR__ . '/.env');
            $settings['dpp_secret_key'] = $_ENV['DPP_SECRET_KEY'] ?? '讀取失敗或未設定';

            send_json_response($settings);
            break;

        case 'update_settings':
            if ($_SESSION['username'] !== 'superadmin') {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足。']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            try {
                // 1. 更新資料庫中的一般設定
                $db->beginTransaction();
                if (isset($input['allow_registration'])) {
                    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'allow_registration'");
                    $stmt->execute([$input['allow_registration']]);
                }
                if (isset($input['company_name'])) {
                    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'company_name'");
                    $stmt->execute([$input['company_name']]);
                }
                if (isset($input['company_url'])) {
                    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'company_url'");
                    $stmt->execute([$input['company_url']]);
                }
                $db->commit();

                // 2. ▼▼▼ 【核心修改】更新 .env 檔案中的密鑰 ▼▼▼
                if (isset($input['dpp_secret_key']) && !empty($input['dpp_secret_key'])) {
                    $env_path = __DIR__ . '/.env';
                    if (is_writable($env_path)) {
                        $env_content = file_get_contents($env_path);
                        $new_key = $input['dpp_secret_key'];
                        // 使用正規表達式安全地取代現有的 KEY
                        $env_content = preg_replace(
                            '/^DPP_SECRET_KEY=.*$/m',
                            'DPP_SECRET_KEY="' . $new_key . '"',
                            $env_content,
                            1, // 只取代一次
                            $count
                        );
                        // 如果原本沒有 KEY，則在檔案末端新增
                        if ($count == 0) {
                            $env_content .= "\nDPP_SECRET_KEY=\"" . $new_key . "\"";
                        }
                        file_put_contents($env_path, $env_content, LOCK_EX);
                    } else {
                        throw new Exception('.env 檔案不可寫入，請檢查伺服器權限。');
                    }
                }

                send_json_response(['success' => true, 'message' => '設定已更新。']);

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                http_response_code(500);
                send_json_response(['success' => false, 'message' => '更新設定時發生錯誤: ' . $e->getMessage()]);
            }
            break;
        case 'logout':
            session_unset();
            session_destroy();
            header('Location: login.php');
            exit;
        case 'delete_project':
            $input = json_decode(file_get_contents('php://input'), true);
            $projectId = $input['project_id'] ?? null;

            if (!$projectId) {
                http_response_code(400);
                send_json_response(['success' => false, 'message' => '缺少專案 ID。']);
            }

            try {
                $db->beginTransaction();

                // 步驟 1: 找出此專案下所有報告的 view_id，並驗證所有權
                $stmt_find = $db->prepare("SELECT view_id FROM reports WHERE project_id = ? AND user_id = ?");
                $stmt_find->execute([$projectId, $user_id]);
                $view_ids_to_delete = $stmt_find->fetchAll(PDO::FETCH_COLUMN);

                // 步驟 2: 從 reports 資料表中刪除所有相關報告記錄 (加入 user_id 條件)
                $stmt_del_reports = $db->prepare("DELETE FROM reports WHERE project_id = ? AND user_id = ?");
                $stmt_del_reports->execute([$projectId, $user_id]);

                // 步驟 3: 從 projects 資料表中刪除專案本身 (加入 user_id 條件)
                $stmt_del_project = $db->prepare("DELETE FROM projects WHERE id = ? AND user_id = ?");
                $stmt_del_project->execute([$projectId, $user_id]);

                // 步驟 4: 刪除實體的 JSON 報告檔案
                foreach ($view_ids_to_delete as $view_id) {
                    $filePath = RESULTS_DIR . '/' . basename($view_id) . '.json';
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }

                $db->commit();
                send_json_response(['success' => true, 'message' => '專案及其所有報告已成功刪除。']);

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                http_response_code(500);
                send_json_response(['success' => false, 'message' => '刪除專案時發生資料庫錯誤: ' . $e->getMessage()]);
            }
            break;
        case 'get_report_templates':
            $templates_dir = __DIR__ . '/report_templates';
            $files = scandir($templates_dir);
            $templates = [];
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $content = file_get_contents($templates_dir . '/' . $file);
                    $name = $file;
                    if (preg_match('/^\s*\<\?php\s*\/\/\s*(.+)/', $content, $matches)) {
                        $name = trim($matches[1]);
                    }
                    $templates[] = ['file' => $file, 'name' => $name];
                }
            }
            send_json_response($templates);
            break;
        case 'carousel_slider_report':
            $viewId = basename($_GET['view_id'] ?? '');
            $templateFile = basename($_GET['template_file'] ?? '');

            // [新增安全驗證] 檢查報告是否屬於目前使用者
            $stmt_check_owner = $db->prepare("SELECT COUNT(id) FROM reports WHERE view_id = ? AND user_id = ?");
            $stmt_check_owner->execute([$viewId, $user_id]);
            if ($stmt_check_owner->fetchColumn() == 0) {
                http_response_code(403);
                die('權限不足，無法存取此報告。');
            }

            $templates_dir = __DIR__ . '/report_templates';
            $available_templates = array_filter(scandir($templates_dir), fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'php');
            if (empty($templateFile) || !in_array($templateFile, $available_templates)) {
                http_response_code(400);
                die('錯誤：無效或不安全的樣板檔案名稱。');
            }

            $filePath = RESULTS_DIR . '/' . $viewId . '.json';
            if (file_exists($filePath)) {
                render_report_template(file_get_contents($filePath), $templateFile);
                exit;
            } else {
                http_response_code(404);
                die('報告不存在。');
            }
            break;

        case 'run_scenario_analysis':
            $input = json_decode(file_get_contents('php://input'), true);
            $bom = $input['bom'] ?? [];
            $scenario = $input['scenario'] ?? [];

            if (empty($bom['components']) || empty($scenario)) {
                http_response_code(400);
                send_json_response(['success' => false, 'message' => '缺少BOM或情境設定。']);
                break;
            }

            // --- 敏感度分析 ---
            if ($scenario['type'] !== 'financial') {
                $keys = array_unique(array_column($bom['components'], 'materialKey'));
                if (empty($keys)) {
                    http_response_code(400); send_json_response(['success' => false, 'message' => 'BOM中沒有有效的物料。']); break;
                }
                $placeholders = rtrim(str_repeat('?,', count($keys)), ',');
                $stmt = $db->prepare("SELECT * FROM materials WHERE key IN ($placeholders)");
                $stmt->execute($keys);
                $materials_map = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'key');

                // 【核心修正 1/3】在此處補上查詢 process 資料的完整邏輯
                $processKeys = array_unique(array_column(array_filter($bom['components'], fn($c) => $c['componentType'] === 'process'), 'processKey'));
                $processes_map = [];
                if (!empty($processKeys)) {
                    $placeholders_proc = rtrim(str_repeat('?,', count($processKeys)), ',');
                    $sql_proc = "SELECT * FROM processes WHERE process_key IN ($placeholders_proc)";
                    $stmt_proc = $db->prepare($sql_proc);
                    $stmt_proc->execute(array_values($processKeys));
                    $processes_data_raw = $stmt_proc->fetchAll(PDO::FETCH_ASSOC);
                    $stmt_opts = $db->query("SELECT process_key, id, option_key, option_name FROM process_options");
                    $options_by_proc_key = $stmt_opts->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
                    $stmt_mults = $db->query("SELECT option_id, item_key, item_name, energy_multiplier FROM process_option_multipliers");
                    $multipliers_by_opt_id = $stmt_mults->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
                    foreach($processes_data_raw as $proc) {
                        $proc_key = $proc['process_key'];
                        $proc['options'] = [];
                        if (isset($options_by_proc_key[$proc_key])) {
                            foreach ($options_by_proc_key[$proc_key] as $opt) {
                                $option_id = $opt['id'];
                                $option_key = $opt['option_key'];
                                $choices = $multipliers_by_opt_id[$option_id] ?? [];
                                $formatted_choices = array_map(function($choice) { return [ 'key' => $choice['item_key'], 'name' => $choice['item_name'], 'energy_multiplier' => (float)$choice['energy_multiplier'] ]; }, $choices);
                                $proc['options'][$option_key] = [ 'name' => $opt['option_name'], 'choices' => $formatted_choices ];
                            }
                        }
                        $processes_map[$proc_key] = $proc;
                    }
                }

                $results = [];
                $start = floatval($scenario['start_val']);
                $end = floatval($scenario['end_val']);
                $step = floatval($scenario['step_val']);
                if ($step <= 0) $step = ($end > $start) ? ($end - $start) / 10 : 1;

                for ($pct = $start; $pct <= $end; $pct += $step) {
                    $current_bom = $bom['components'];
                    $modifiers = [];

                    switch ($scenario['type']) {
                        case 'cost':
                            foreach ($current_bom as &$c) {
                                if (($c['componentType'] ?? 'material') === 'material' && ($scenario['target_key'] === 'all' || $scenario['target_key'] === $c['materialKey'])) {
                                    $base_cost = isset($c['cost']) && $c['cost'] !== '' ? floatval($c['cost']) : ($materials_map[$c['materialKey']]['cost_per_kg'] ?? 0);
                                    $c['cost'] = $base_cost * (1 + $pct / 100);
                                }
                            }
                            unset($c);
                            break;
                        case 'circularity':
                            foreach ($current_bom as &$c) {
                                if (($c['componentType'] ?? 'material') === 'material' && ($scenario['target_key'] === 'all' || $scenario['target_key'] === $c['materialKey'])) {
                                    $c['percentage'] = $pct;
                                }
                            }
                            unset($c);
                            break;
                        case 'risk':
                            if ($scenario['target_key'] !== 'all') {
                                $modifiers[$scenario['target_key']] = (1 + $pct / 100);
                            }
                            break;
                    }

                    // 【核心修正 2/3】使用完整的 BOM 和新增的 processes_map 進行計算
                    $lca_result = calculate_lca_from_bom($current_bom, $bom['eol'], $materials_map, $processes_map);
                    if (!$lca_result['success']) continue;

                    // 【核心修正 3/3】建立一個只包含物料的 BOM，供下游模組使用
                    $material_components_only = array_filter($current_bom, fn($c) => ($c['componentType'] ?? 'material') === 'material');

                    $social_result = calculate_social_impact($material_components_only, $materials_map, $modifiers);
                    $gov_result = calculate_governance_impact($material_components_only, $materials_map, $modifiers);
                    $biodiversity_result = calculate_biodiversity_impact($material_components_only, $materials_map);
                    $water_scarcity_result = calculate_water_scarcity_impact($material_components_only, $materials_map);
                    $resource_depletion_result = calculate_resource_depletion_impact($material_components_only, $materials_map);

                    $circularity_result = calculate_circularity_score($lca_result, $materials_map);
                    $environmental_performance = calculate_environmental_performance($lca_result, $circularity_result, $biodiversity_result, $water_scarcity_result, $resource_depletion_result);
                    $esg_scores = calculate_esg_score($environmental_performance, $social_result, $gov_result, $biodiversity_result, $water_scarcity_result, $resource_depletion_result);

                    $total_cost = $lca_result['impact']['cost'];
                    if ($scenario['type'] === 'carbon_tax') {
                        $total_cost += $lca_result['impact']['co2'] * $pct;
                    }
                    $results[] = ['step' => $pct, 'total_cost' => round($total_cost, 2), 'total_co2' => round($lca_result['impact']['co2'], 3), 'esg_score' => $esg_scores['combined_score']];
                }
                send_json_response(['success' => true, 'results' => $results, 'type' => 'sensitivity']);
                break;
            }

            // --- 財務風險模擬 (此部分邏輯正確，無需修改) ---
            if ($scenario['type'] === 'financial') {
                $countries_db_content = file_get_contents(__DIR__ . '/countries_db.json');
                $countries_db = json_decode($countries_db_content, true);
                $country_currency_map = array_column($countries_db, 'currency', 'en');

                $keys = array_unique(array_column($bom['components'], 'materialKey'));
                $placeholders = rtrim(str_repeat('?,', count($keys)), ',');
                $stmt = $db->prepare("SELECT `key`, cost_per_kg, country_of_origin FROM materials WHERE key IN ($placeholders)");
                $stmt->execute($keys);
                $materials_map = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'key');

                $base_currency = $scenario['base_currency'] ?? 'TWD';
                $exchange_rates = $scenario['exchange_rates'] ?? [];
                $tariff_rules = $scenario['tariff_rules'] ?? [];

                $total_baseline_cost = 0;
                $total_final_cost = 0;
                $total_tariff_cost = 0;

                foreach($bom['components'] as $c) {
                    $key = $c['materialKey'];
                    if (!isset($materials_map[$key])) continue;

                    $material = $materials_map[$key];
                    $weight = floatval($c['weight']);
                    $cost_per_kg_local = isset($c['cost']) && $c['cost'] !== '' ? floatval($c['cost']) : ($material['cost_per_kg'] ?? 0);

                    $origins = json_decode($material['country_of_origin'] ?? '[]', true);
                    if (!is_array($origins) || empty($origins)) {
                        $total_baseline_cost += $weight * $cost_per_kg_local;
                        $total_final_cost += $weight * $cost_per_kg_local;
                        continue;
                    }

                    $component_final_cost = 0;
                    $component_baseline_cost = 0;
                    foreach($origins as $origin) {
                        $country_en = $origin['country'] ?? 'Unknown';
                        $percentage = floatval($origin['percentage'] ?? 100) / 100;
                        $origin_weight = $weight * $percentage;

                        $source_currency = $country_currency_map[$country_en] ?? $base_currency;
                        $origin_cost_local = $origin_weight * $cost_per_kg_local;

                        // 計算基準成本 (換算成基準貨幣，但無關稅)
                        $baseline_cost_in_base_currency = $origin_cost_local;
                        if ($source_currency !== $base_currency && isset($exchange_rates[$source_currency])) {
                            $rate = floatval($exchange_rates[$source_currency]);
                            if($rate > 0) $baseline_cost_in_base_currency = $origin_cost_local / $rate;
                        }
                        $component_baseline_cost += $baseline_cost_in_base_currency;

                        // 計算關稅
                        $tariff_cost = 0;
                        foreach($tariff_rules as $rule) {
                            if ($rule['country'] === $country_en) {
                                $tariff_rate = floatval($rule['percentage'] ?? 0) / 100;
                                $tariff_cost = $baseline_cost_in_base_currency * $tariff_rate;
                                $total_tariff_cost += $tariff_cost;
                                break;
                            }
                        }

                        $component_final_cost += $baseline_cost_in_base_currency + $tariff_cost;
                    }
                    $total_baseline_cost += $component_baseline_cost;
                    $total_final_cost += $component_final_cost;
                }

                send_json_response([
                    'success' => true,
                    'type' => 'financial',
                    'results' => [
                        'baseline_cost' => round($total_baseline_cost, 2),
                        'final_cost' => round($total_final_cost, 2),
                        'tariff_cost' => round($total_tariff_cost, 2),
                        'base_currency' => $base_currency
                    ]
                ]);
                break;
            }
            break;

        case 'find_optimal_bom':
            $request = json_decode(file_get_contents('php://input'), true);
            $constraints = $request['constraints'];
            $original_bom_input = $request['original_bom'];
            $main_goal = $request['main_goal'] ?? 'minimize_co2';
            $has_cost_data = $request['has_cost_data'] ?? false;

            $all_materials = $db->query("SELECT * FROM materials WHERE key NOT LIKE 'DB_EXTENDED_V%'")->fetchAll(PDO::FETCH_ASSOC);
            $materials_map = array_column($all_materials, null, 'key');

            // 【核心修正 1/5】由於 LCA 引擎需要 processes_map，在此處補上查詢邏輯
            $processKeys = array_unique(array_column(array_filter($original_bom_input['components'], fn($c) => $c['componentType'] === 'process'), 'processKey'));
            $processes_map = [];
            if (!empty($processKeys)) {
                $placeholders_proc = rtrim(str_repeat('?,', count($processKeys)), ',');
                $sql_proc = "SELECT * FROM processes WHERE process_key IN ($placeholders_proc)";
                $stmt_proc = $db->prepare($sql_proc);
                $stmt_proc->execute(array_values($processKeys));
                $processes_data_raw = $stmt_proc->fetchAll(PDO::FETCH_ASSOC);
                $stmt_opts = $db->query("SELECT process_key, id, option_key, option_name FROM process_options");
                $options_by_proc_key = $stmt_opts->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
                $stmt_mults = $db->query("SELECT option_id, item_key, item_name, energy_multiplier FROM process_option_multipliers");
                $multipliers_by_opt_id = $stmt_mults->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
                foreach($processes_data_raw as $proc) {
                    $proc_key = $proc['process_key'];
                    $proc['options'] = [];
                    if (isset($options_by_proc_key[$proc_key])) {
                        foreach ($options_by_proc_key[$proc_key] as $opt) {
                            $option_id = $opt['id'];
                            $option_key = $opt['option_key'];
                            $choices = $multipliers_by_opt_id[$option_id] ?? [];
                            $formatted_choices = array_map(function($choice) { return [ 'key' => $choice['item_key'], 'name' => $choice['item_name'], 'energy_multiplier' => (float)$choice['energy_multiplier'] ]; }, $choices);
                            $proc['options'][$option_key] = [ 'name' => $opt['option_name'], 'choices' => $formatted_choices ];
                        }
                    }
                    $processes_map[$proc_key] = $proc;
                }
            }

            // 【核心修正 2/5】確保 $original_bom_full_data 包含所有類型的組件 (物料+製程)
            $original_bom_full_data = [];
            foreach ($original_bom_input['components'] as $c) {
                if (($c['componentType'] ?? 'material') === 'material') {
                    if (isset($materials_map[$c['materialKey']])) {
                        $component_data = $c;
                        $component_data['cost'] = (!empty($c['cost'])) ? (float)$c['cost'] : ($materials_map[$c['materialKey']]['cost_per_kg'] ?? 0);
                        $original_bom_full_data[] = $component_data;
                    }
                } else {
                    // 直接加入製程組件
                    $original_bom_full_data[] = $c;
                }
            }

            if (empty($original_bom_full_data)) { send_json_response(['success' => false, 'message' => '原始BOM無效。']); break; }

            // 【核心修正 3/5】使用完整的 BOM 進行基準計算
            $original_result = calculate_lca_from_bom($original_bom_full_data, $original_bom_input['eol'], $materials_map, $processes_map);
            $original_social_result = calculate_social_impact(array_filter($original_bom_full_data, fn($c) => ($c['componentType'] ?? 'material') === 'material'), $materials_map);
            $original_governance_result = calculate_governance_impact(array_filter($original_bom_full_data, fn($c) => ($c['componentType'] ?? 'material') === 'material'), $materials_map);

            if (!$original_result['success']) { send_json_response(['success' => false, 'message' => '無法分析原始BOM。']); break; }

            // (後續的 $composition 處理邏輯不變)
            $composition = $original_result['charts']['composition'];
            foreach ($composition as &$c) {
                if (!isset($materials_map[$c['key']])) continue; // 跳過製程
                $material_data = $materials_map[$c['key']] ?? [];
                $c['s_risk_contribution'] = ($material_data['social_risk_score'] ?? 50) * $c['weight'];
                $c['g_risk_contribution'] = ($material_data['governance_risk_score'] ?? 30) * $c['weight'];
                $c['co2_contribution'] = $c['co2'];
            }
            unset($c);

            $sort_key = 'co2_contribution';
            if ($main_goal === 'minimize_s_risk') $sort_key = 's_risk_contribution';
            if ($main_goal === 'minimize_g_risk') $sort_key = 'g_risk_contribution';
            usort($composition, fn($a, $b) => ($b[$sort_key] ?? 0) <=> ($a[$sort_key] ?? 0));

            // 只針對「物料」類型的熱點進行優化
            $optimization_targets = array_slice(array_filter($composition, fn($c) => isset($c['weight']) && $c['weight'] > 0), 0, 3);

            $solutions = [];
            foreach ($optimization_targets as $target) {
                $target_material_data = $materials_map[$target['key']] ?? null;
                if (!$target_material_data) continue;

                $original_component = null;
                foreach($original_bom_full_data as $c) { if(($c['componentType'] ?? 'material') === 'material' && $c['materialKey'] === $target['key']) { $original_component = $c; break; } }

                if ($original_component && (float)$original_component['percentage'] < 100) {
                    // 【核心修正 4/5】建立新 BOM 時，要基於完整的 BOM
                    $new_bom_components_circ = array_map(function($c) use ($target) {
                        if (($c['componentType'] ?? 'material') === 'material' && $c['materialKey'] === $target['key']) {
                            return array_merge($c, ['percentage' => 100]);
                        }
                        return $c;
                    }, $original_bom_full_data);

                    $new_result_circ = calculate_lca_from_bom($new_bom_components_circ, $original_bom_input['eol'], $materials_map, $processes_map);

                    if ($new_result_circ['success']) {
                        $co2_imp_circ = $original_result['impact']['co2'] - $new_result_circ['impact']['co2'];
                        if ($co2_imp_circ > 0) {
                            $solutions[] = ['co2_delta' => $co2_imp_circ, 'cost_delta' => $original_result['impact']['cost'] - $new_result_circ['impact']['cost'], 's_risk_delta' => 0, 'g_risk_delta' => 0, 'description' => "將 <b>{$target['name']}</b> 的再生料比例提升至 100%", 'category' => 'circular_upgrade', 'bom' => $new_result_circ['charts']['composition']];
                        }
                    }
                }

                foreach ($all_materials as $candidate_material) {
                    if ($candidate_material['key'] === $target['key']) continue;

                    // 【核心修正 5/5】建立新 BOM 時，要基於完整的 BOM，並只替換物料
                    $new_bom_components = array_map(function($c) use ($target, $candidate_material) {
                        if (($c['componentType'] ?? 'material') === 'material' && $c['materialKey'] === $target['key']) {
                            return ['componentType' => 'material', 'materialKey' => $candidate_material['key'], 'weight' => $c['weight'], 'percentage' => $c['percentage'], 'cost' => $candidate_material['cost_per_kg'] ?? 0];
                        }
                        return $c;
                    }, $original_bom_full_data);

                    $new_material_bom_only = array_filter($new_bom_components, fn($c) => ($c['componentType'] ?? 'material') === 'material');

                    $new_result = calculate_lca_from_bom($new_bom_components, $original_bom_input['eol'], $materials_map, $processes_map);
                    if (!$new_result['success']) continue;

                    $new_social_result = calculate_social_impact($new_material_bom_only, $materials_map);
                    $new_governance_result = calculate_governance_impact($new_material_bom_only, $materials_map);

                    $co2_imp = $original_result['impact']['co2'] - $new_result['impact']['co2'];
                    $cost_imp = $original_result['impact']['cost'] - $new_result['impact']['cost'];
                    $s_risk_imp = $original_social_result['overall_risk_score'] - $new_social_result['overall_risk_score'];
                    $g_risk_imp = $original_governance_result['overall_risk_score'] - $new_governance_result['overall_risk_score'];

                    $category = 'alternative';
                    if ($target_material_data['category'] !== $candidate_material['category'] && $co2_imp > 0) { $category = 'innovative_leap'; }
                    elseif ($cost_imp > ($original_result['impact']['cost'] * 0.05)) { $category = 'cost_saver'; }
                    elseif ($co2_imp > (abs($original_result['impact']['co2']) * 0.20)) { $category = 'high_potential'; }
                    elseif ($cost_imp >= -($original_result['impact']['cost'] * 0.05) && $co2_imp > 0.001) { $category = 'quick_win'; }
                    $solutions[] = ['co2_delta' => $co2_imp, 'cost_delta' => $cost_imp, 's_risk_delta' => $s_risk_imp, 'g_risk_delta' => $g_risk_imp, 'description' => "將 <b>{$target['name']}</b> 替換為 <b>{$candidate_material['name']}</b>", 'category' => $category, 'bom' => $new_result['charts']['composition']];
                }
            }

            $total_co2_base = abs($original_result['impact']['co2']) > 0.001 ? abs($original_result['impact']['co2']) : 1;
            $total_cost_base = $original_result['impact']['cost'] > 0.001 ? $original_result['impact']['cost'] : 1;
            $total_s_risk_base = $original_social_result['overall_risk_score'] > 0 ? $original_social_result['overall_risk_score'] : 1;
            $total_g_risk_base = $original_governance_result['overall_risk_score'] > 0 ? $original_governance_result['overall_risk_score'] : 1;

            usort($solutions, function($a, $b) use ($main_goal, $total_co2_base, $total_cost_base, $total_s_risk_base, $total_g_risk_base) {
                $score_a = 0; $score_b = 0;
                $norm_co2_a = ($a['co2_delta'] ?? 0) / $total_co2_base; $norm_cost_a = ($a['cost_delta'] ?? 0) / $total_cost_base; $norm_s_risk_a = ($a['s_risk_delta'] ?? 0) / $total_s_risk_base; $norm_g_risk_a = ($a['g_risk_delta'] ?? 0) / $total_g_risk_base;
                $norm_co2_b = ($b['co2_delta'] ?? 0) / $total_co2_base; $norm_cost_b = ($b['cost_delta'] ?? 0) / $total_cost_base; $norm_s_risk_b = ($b['s_risk_delta'] ?? 0) / $total_s_risk_base; $norm_g_risk_b = ($b['g_risk_delta'] ?? 0) / $total_g_risk_base;

                switch ($main_goal) {
                    case 'minimize_s_risk': $score_a = $norm_s_risk_a * 0.7 + $norm_co2_a * 0.2 + $norm_cost_a * 0.1; $score_b = $norm_s_risk_b * 0.7 + $norm_co2_b * 0.2 + $norm_cost_b * 0.1; break;
                    case 'minimize_g_risk': $score_a = $norm_g_risk_a * 0.7 + $norm_co2_a * 0.2 + $norm_cost_a * 0.1; $score_b = $norm_g_risk_b * 0.7 + $norm_co2_b * 0.2 + $norm_cost_b * 0.1; break;
                    case 'minimize_cost': $score_a = $norm_cost_a * 0.7 + $norm_co2_a * 0.3; $score_b = $norm_cost_b * 0.7 + $norm_co2_b * 0.3; break;
                    case 'balanced': $score_a = $norm_co2_a * 0.5 + $norm_cost_a * 0.5; $score_b = $norm_co2_b * 0.5 + $norm_cost_b * 0.5; break;
                    case 'maximize_circularity': if (($a['category'] ?? '') === 'circular_upgrade') $score_a += 1000; if (($b['category'] ?? '') === 'circular_upgrade') $score_b += 1000; $score_a += $norm_co2_a * 0.6 + $norm_cost_a * 0.4; $score_b += $norm_co2_b * 0.6 + $norm_cost_b * 0.4; break;
                    case 'minimize_co2': default: $score_a = $norm_co2_a * 0.7 + $norm_cost_a * 0.2 + $norm_s_risk_a * 0.1; $score_b = $norm_co2_b * 0.7 + $norm_cost_b * 0.2 + $norm_s_risk_b * 0.1; break;
                }
                return $score_b <=> $score_a;
            });
            send_json_response(['success' => true, 'recommendations' => array_slice($solutions, 0, 15), 'base_scores' => ['co2' => $original_result['impact']['co2'], 'cost' => $original_result['impact']['cost'], 's_risk' => $original_social_result['overall_risk_score'], 'g_risk' => $original_governance_result['overall_risk_score']]]);
            break;

        case 'clear_all_history':
            // [修改] 此功能變為清除「目前使用者」的所有歷史紀錄
            try {
                $db->beginTransaction();

                // 找出該使用者的所有報告 view_id 以便刪除 JSON 檔
                $stmt_find_reports = $db->prepare("SELECT view_id FROM reports WHERE user_id = ?");
                $stmt_find_reports->execute([$user_id]);
                $view_ids_to_delete = $stmt_find_reports->fetchAll(PDO::FETCH_COLUMN);

                // 刪除該使用者的所有 reports
                $stmt_del_reports = $db->prepare("DELETE FROM reports WHERE user_id = ?");
                $stmt_del_reports->execute([$user_id]);

                // 刪除該使用者的所有 projects
                $stmt_del_projects = $db->prepare("DELETE FROM projects WHERE user_id = ?");
                $stmt_del_projects->execute([$user_id]);

                $db->commit();

                // 刪除對應的 JSON 檔案
                foreach($view_ids_to_delete as $view_id){
                    $filePath = RESULTS_DIR . '/' . basename($view_id) . '.json';
                    if(is_file($filePath)) { unlink($filePath); }
                }

                send_json_response(['success' => true, 'message' => '您的所有歷史記錄已成功清除。']);

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                http_response_code(500);
                send_json_response(['success' => false, 'message' => '清除歷史記錄時發生錯誤: ' . $e->getMessage()]);
            }
            break;

        case 'get_projects':
            // 增加一個可選的 organization_id 篩選參數
            $org_id = (int)($_GET['organization_id'] ?? 0);

            if ($org_id > 0) {
                // 【新邏輯】如果提供了 organization_id，就抓取該組織下的專案
                $stmt = $db->prepare("SELECT id, name, organization_id FROM projects WHERE user_id = ? AND organization_id = ? ORDER BY name ASC");
                $stmt->execute([$user_id, $org_id]);
            } else {
                // 【舊資料相容邏輯】如果沒提供 organization_id (代表想找未歸屬)，就抓取 organization_id 是空的專案
                $stmt = $db->prepare("SELECT id, name, organization_id FROM projects WHERE user_id = ? AND (organization_id IS NULL OR organization_id = 0) ORDER BY name ASC");
                $stmt->execute([$user_id]);
            }

            send_json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
        case 'get_user_organizations':
            // 安全驗證：只抓取目前登入使用者的組織
            $stmt = $db->prepare("SELECT id, name FROM organizations WHERE user_id = ? ORDER BY name ASC");
            $stmt->execute([$user_id]);
            send_json_response(['success' => true, 'organizations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'save_report':
            handle_save_report_request($db);
            break; // 此處 break 只是為了程式碼結構，實際上函式會 exit

        case 'get_comparison_data':
            $ids = $_GET['ids'] ?? [];
            if (count($ids) !== 2) {
                http_response_code(400);
                send_json_response(['success' => false, 'message' => '請選擇剛好兩份報告進行比較。']);
            }

            $user_id = $_SESSION['user_id'];
            $cleanViewIds = array_map('basename', $ids);

            // 【效能優化】與上方 carousel 邏輯相同，一次性查詢所有 view_id 來驗證所有權
            $placeholders = implode(',', array_fill(0, count($cleanViewIds), '?'));
            $sql = "SELECT view_id FROM reports WHERE view_id IN ($placeholders) AND user_id = ?";
            $stmt = $db->prepare($sql);

            $params = $cleanViewIds;
            $params[] = $user_id;
            $stmt->execute($params);

            $ownedViewIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $ownedSet = array_flip($ownedViewIds); // 建立一個查找表以便快速檢查

            $summarizedData = [];
            // 【效能優化】現在的迴圈只處理檔案讀取，不再有資料庫操作
            foreach ($cleanViewIds as $viewId) {
                if (isset($ownedSet[$viewId])) { // 檢查是否擁有權限
                    $filePath = RESULTS_DIR . '/' . $viewId . '.json';
                    if (file_exists($filePath)) {
                        $fullReport = json_decode(file_get_contents($filePath), true);

                        // （此處的摘要邏輯保持不變）
                        $summary = [
                            'versionName' => $fullReport['versionName'] ?? '未命名',
                            'impact' => $fullReport['impact'] ?? [],
                            'virgin_impact' => $fullReport['virgin_impact'] ?? [],
                            'inputs' => [
                                'components' => $fullReport['inputs']['components'] ?? [],
                                'totalWeight' => $fullReport['inputs']['totalWeight'] ?? 0,
                            ],
                            'charts' => [
                                'composition' => $fullReport['charts']['composition'] ?? [],
                                'content_by_type' => $fullReport['charts']['content_by_type'] ?? [],
                            ],
                            'environmental_fingerprint_scores' => $fullReport['environmental_fingerprint_scores'] ?? [],
                            'social_impact' => ['overall_risk_score' => $fullReport['social_impact']['overall_risk_score'] ?? null],
                            'governance_impact' => ['overall_risk_score' => $fullReport['governance_impact']['overall_risk_score'] ?? null],
                        ];
                        $summarizedData[] = $summary;
                    }
                }
            }

            if (count($summarizedData) === 2) {
                send_json_response(['success' => true, 'data' => $summarizedData]);
            } else {
                http_response_code(404);
                send_json_response(['success' => false, 'message' => '找不到一份或多份報告的數據檔案，或權限不足。']);
            }
            break;

        case 'detailed_report':
            $viewId = basename($_GET['view_id'] ?? '');

            // [新增安全驗證] 檢查報告是否屬於目前使用者
            $stmt_check_owner = $db->prepare("SELECT COUNT(id) FROM reports WHERE view_id = ? AND user_id = ?");
            $stmt_check_owner->execute([$viewId, $user_id]);
            if ($stmt_check_owner->fetchColumn() == 0) {
                http_response_code(403);
                die('權限不足，無法檢視此報告。');
            }

            $filePath = RESULTS_DIR . '/' . $viewId . '.json';
            if (file_exists($filePath)) {
                render_detailed_report_page(file_get_contents($filePath));
                exit;
            } else {
                http_response_code(404);
                die('報告不存在。');
            }
            break;
        case 'get_reports':
            // [修改] 只取得該使用者的報告
            $stmt = $db->prepare("
                SELECT r.id, r.view_id, p.name as project_name, r.version_name, r.total_weight_kg, r.total_co2e_kg, 
                       strftime('%Y-%m-%d %H:%M', r.created_at, 'localtime') as created_at, r.project_id 
                FROM reports r 
                LEFT JOIN projects p ON r.project_id = p.id 
                WHERE r.user_id = ? 
                ORDER BY p.name, r.created_at DESC
            ");
            $stmt->execute([$user_id]);
            send_json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'get_report_data':
            $viewId = basename($_GET['view_id'] ?? '');

            // [新增安全驗證] 檢查報告是否屬於目前使用者
            $stmt_check_owner = $db->prepare("SELECT COUNT(id) FROM reports WHERE view_id = ? AND user_id = ?");
            $stmt_check_owner->execute([$viewId, $user_id]);
            if ($stmt_check_owner->fetchColumn() == 0) {
                http_response_code(403);
                send_json_response(['success' => false, 'message' => '權限不足，無法存取此報告數據。']);
                break;
            }

            $filePath = RESULTS_DIR . '/' . $viewId . '.json';
            if (file_exists($filePath)) {
                header('Content-Type: application/json; charset=utf-8');
                echo file_get_contents($filePath);
                exit;
            } else {
                http_response_code(404);
                send_json_response(['success' => false, 'message' => '報告數據不存在。']);
            }
            break;

        case 'delete_report':
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? null;
            if ($id) {
                $db->beginTransaction();
                // [修改] 刪除前先驗證報告所有權
                $stmt_get = $db->prepare("SELECT view_id FROM reports WHERE id = ? AND user_id = ?");
                $stmt_get->execute([$id, $user_id]);
                $view_id = $stmt_get->fetchColumn();

                if ($view_id === false) {
                    $db->rollBack();
                    send_json_response(['success' => false, 'message' => '找不到報告或權限不足。']);
                    break;
                }

                // [修改] 刪除時也加入 user_id 條件
                $stmt_del = $db->prepare("DELETE FROM reports WHERE id = ? AND user_id = ?");
                $stmt_del->execute([$id, $user_id]);

                if ($stmt_del->rowCount() > 0) {
                    $filePath = RESULTS_DIR . '/' . basename($view_id) . '.json';
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                    $db->commit();
                    send_json_response(['success' => true, 'message' => '報告已刪除。']);
                } else {
                    $db->rollBack();
                    send_json_response(['success' => false, 'message' => '刪除失敗。']);
                }
            } else {
                http_response_code(400);
                send_json_response(['success' => false, 'message' => '缺少報告 ID。']);
            }
            break;

        default:
            http_response_code(400); // Bad Request
            send_json_response(['success' => false, 'message' => '錯誤：無效的操作請求。']);
            break;

    } }if (isset($_GET['view'])) { $viewId = basename($_GET['view']); $filePath = RESULTS_DIR . '/' . $viewId . '.json'; if (file_exists($filePath)) { render_embed_page(file_get_contents($filePath)); exit; } else { http_response_code(404); die('報告不存在或已過期。'); } }
else if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action'])) {
    handle_calculation_request(initialize_database());
}
$db = initialize_database();
$all_materials = $db->query("SELECT * FROM materials WHERE key NOT LIKE 'DB_EXTENDED_V%' ORDER BY category, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$materials_json = json_encode($all_materials, JSON_UNESCAPED_UNICODE);

// 【核心修改】在此處也取得所有製程資料，並傳給前端
$all_processes_stmt = $db->query("SELECT * FROM processes ORDER BY category, name ASC");
$all_procs = $all_processes_stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt_opts = $db->query("SELECT process_key, id, option_key, option_name FROM process_options");
$options_by_proc_key = $stmt_opts->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
$stmt_mults = $db->query("SELECT option_id, item_key, item_name, energy_multiplier FROM process_option_multipliers");
$multipliers_by_opt_id = $stmt_mults->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
$final_processes = [];
foreach ($all_procs as $proc) {
    $proc_key = $proc['process_key'];
    $proc['options'] = [];
    if (isset($options_by_proc_key[$proc_key])) {
        foreach ($options_by_proc_key[$proc_key] as $opt) {
            $option_id = $opt['id'];
            $option_key = $opt['option_key'];
            $choices = $multipliers_by_opt_id[$option_id] ?? [];
            $formatted_choices = array_map(function($choice) {
                return [
                    'key' => $choice['item_key'],
                    'name' => $choice['item_name'],
                    'energy_multiplier' => (float)$choice['energy_multiplier']
                ];
            }, $choices);
            $proc['options'][$option_key] = [
                'name' => $opt['option_name'],
                'choices' => $formatted_choices
            ];
        }
    }
    $final_processes[] = $proc;
}
$processes_json = json_encode($final_processes, JSON_UNESCAPED_UNICODE);

// 2. 【全新】智慧準備地圖所需的國家座標
// 讀取完整的國家資料庫
$countries_db_content = file_exists(__DIR__ . '/countries_db.json') ? file_get_contents(__DIR__ . '/countries_db.json') : '[]';
$countries_db = json_decode($countries_db_content, true);
$countries_lookup = array_column($countries_db, null, 'en'); // 建立一個以便用英文名快速查找的陣列

// 找出物料庫中所有被使用到的國家
$used_countries_en = [];
foreach ($all_materials as $material) {
    if (isset($material['country_of_origin'])) {
        $origins = json_decode($material['country_of_origin'], true);
        if (is_array($origins)) {
            foreach ($origins as $origin) {
                if (isset($origin['country'])) {
                    $used_countries_en[] = $origin['country'];
                }
            }
        }
    }
}
$unique_used_countries = array_unique($used_countries_en);

// 只挑出被使用到的國家資料，傳給前端
$frontend_country_data = [];
foreach ($unique_used_countries as $country_en) {
    if (isset($countries_lookup[$country_en])) {
        // 為了讓前端更容易使用，我們直接建立 key-value 格式
        $frontend_country_data[$country_en] = $countries_lookup[$country_en];
    }
}
$country_coords_json = json_encode($frontend_country_data, JSON_UNESCAPED_UNICODE);
$transport_factors_json = file_get_contents(__DIR__ . '/transport_factors.json');
$transport_routes_json = file_get_contents(__DIR__ . '/transport_routes.json');
$grid_factors_json_content = file_exists(__DIR__ . '/grid_factors.json') ? file_get_contents(__DIR__ . '/grid_factors.json') : '{}';
$use_phase_scenarios_json_content = file_exists(__DIR__ . '/use_phase_scenarios.json') ? file_get_contents(__DIR__ . '/use_phase_scenarios.json') : '{}';

?>
<!DOCTYPE html>
<html lang="zh-Hant" data-theme="light-green">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ESG 永續材料決策系統 - AI 永續策略儀表板</title>
    <meta name="description" content="本系統不僅是測量產品碳足跡的工具，更是企業管理氣候風險、優化供應鏈韌性、並向市場與投資人證明其永續承諾的戰略資產。我們透過強大的數據分析與 AI 智慧，幫助企業在產品開發的最前端就植入永續 DNA，確保每一項設計決策都能同時兼顧環境效益與商業成功。我們賦能您的企業，在未來的綠色經濟中穩佔先機。">
    <meta name="keywords" content="ESG, 永續材料, 決策系統, 碳足跡, AI, 供應鏈韌性, 循環經濟, LCA, 生命週期評估">
    <meta name="author" content="立璞資源 x 綠色光譜 x 熊創數位">
    <link rel="canonical" href=""> <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🍃</text></svg>">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png"> <meta property="og:title" content="ESG 永續材料決策系統 - AI 永續策略儀表板">
    <meta property="og:description" content="透過數據與 AI 智慧，在產品開發的最前端就植入永續 DNA，實現環境效益與商業成功的雙贏。">
    <meta property="og:type" content="website">
    <meta property="og:url" content=""> <meta property="og:image" content="assets/img/og-image.png"> <meta property="og:locale" content="zh_TW">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="ESG 永續材料決策系統 - AI 永續策略儀表板">
    <meta name="twitter:description" content="透過數據與 AI 智慧，在產品開發的最前端就植入永續 DNA，實現環境效益與商業成功的雙贏。">
    <meta name="twitter:image" content="assets/img/og-image.png"> <meta name="theme-color" content="#198754">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <link href="assets/css/default/app.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/shepherd.js@10.0.1/dist/css/shepherd.css"/>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-chart-sankey@0.12.1/dist/chartjs-chart-sankey.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.3/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/shepherd.js@10.0.1/dist/js/shepherd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

    <style>
        :root{--bs-body-font-size:1.0625rem;--bs-body-font-family:Roboto,"PingFang TC","Microsoft JhengHei UI","Microsoft JhengHei","Noto Sans TC",sans-serif;--bs-body-line-height:1.6;--sidebar-width:580px;--header-height:58px}
        html[data-theme]{--primary:#0d6efd;--primary-rgb:13,110,253;--secondary:#6c757d;--secondary-rgb:108,117,125;--body-bg:#f8f9fa;--body-color:#212529;--card-bg:#fff;--card-border:rgba(0,0,0,0.1);--tertiary-bg:#e9ecef;--tertiary-color:#000;--heading-color:#000;--muted-color:#6c757d;--bs-link-color-rgb:var(--primary-rgb);--bs-link-hover-color-rgb:var(--primary-rgb)}
        html[data-theme="light-green"]{--primary:#198754;--primary-rgb:25,135,84;--heading-color:#146c43}
        html[data-theme="morandi-pink"]{--primary:#b2888c;--primary-rgb:178,136,140;--body-bg:#f4f0ef;--body-color:#5b514f;--card-bg:#fff;--card-border:#e0d8d7;--tertiary-bg:#e8e1df;--heading-color:#9c6f73;--muted-color:#8c7b79}
        html[data-theme="morandi-blue"]{--primary:#6a8e98;--primary-rgb:106,142,152;--body-bg:#f0f3f4;--body-color:#515e61;--card-bg:#fff;--card-border:#d8dfe1;--tertiary-bg:#e1e7e8;--heading-color:#4a6c75;--muted-color:#798689}
        html[data-theme="morandi-green"]{--primary:#869684;--primary-rgb:134,150,132;--body-bg:#f2f3f1;--body-color:#596158;--card-bg:#fff;--card-border:#dadcd9;--tertiary-bg:#e5e7e4;--heading-color:#677865;--muted-color:#828a81}
        html[data-theme="ocean-blue"]{--primary:#0077b6;--primary-rgb:0,119,182;--body-bg:#f0f8ff;--body-color:#033f63;--card-bg:#fff;--card-border:#cce6ff;--tertiary-bg:#e6f3ff;--heading-color:#025a8d;--muted-color:#4895ef}
        html[data-theme="sunset-orange"]{--primary:#f77f00;--primary-rgb:247,127,0;--body-bg:#fff8f0;--body-color:#4f2c00;--card-bg:#fff;--card-border:#ffe8cc;--tertiary-bg:#fff0e0;--heading-color:#d62828;--muted-color:#fcbf49}
        html[data-theme="earth-tones"]{--primary:#8f7d5b;--primary-rgb:143,125,91;--body-bg:#fdfaf4;--body-color:#5c4e30;--card-bg:#fff;--card-border:#eae4d6;--tertiary-bg:#f4efe2;--heading-color:#706040;--muted-color:#a8997b}
        html[data-theme="grayscale"]{--primary:#555;--primary-rgb:85,85,85;--body-bg:#f5f5f5;--body-color:#333;--card-bg:#fff;--card-border:#ddd;--tertiary-bg:#e9e9e9;--heading-color:#111;--muted-color:#777}
        html[data-theme="morandi-apricot"]{--primary:#c7a48b;--primary-rgb:199,164,139;--body-bg:#f9f6f2;--body-color:#6f5e53;--card-bg:#fff;--card-border:#e9e2d9;--tertiary-bg:#f0ebe4;--heading-color:#a8836a;--muted-color:#a18f82}
        html[data-theme="sakura-dream"]{--primary:#ffb7c5;--primary-rgb:255,183,197;--body-bg:#fff5f7;--body-color:#5c474b;--card-bg:#ffffff;--card-border:#ffe0e5;--tertiary-bg:#ffeef0;--heading-color:#de7b90;--muted-color:#c498a2}
        html[data-theme="dark-green"]{--primary:#20c997;--primary-rgb:32,201,151;--body-bg:#1a1a1a;--body-color:#f8f9fa;--card-bg:#242424;--card-border:#333;--tertiary-bg:#2c2c2c;--tertiary-color:#fff;--heading-color:#20c997;--muted-color:#adb5bd}
        html[data-theme="royal-purple"]{--primary:#8338ec;--primary-rgb:131,56,236;--body-bg:#1e1b26;--body-color:#f0e6ff;--card-bg:#2b2639;--card-border:#433c5a;--tertiary-bg:#37304a;--tertiary-color:#f0e6ff;--heading-color:#a85cf9;--muted-color:#be92f6}
        html[data-theme="lush-forest"]{--primary:#4a7c59;--primary-rgb:74,124,89;--body-bg:#2a2c24;--body-color:#d8d8d0;--card-bg:#32352c;--card-border:#4a4d42;--tertiary-bg:#3f423a;--heading-color:#93b0a0;--muted-color:#8a8d80}
        html[data-theme="cyberpunk-neon"]{--primary:#00f0ff;--primary-rgb:0,240,255;--body-bg:#0d0c1d;--body-color:#d1d0f0;--card-bg:#1a183d;--card-border:#3d3a8a;--tertiary-bg:#232152;--heading-color:#ff00f2;--muted-color:#7b78d1}
        body{background-color:var(--body-bg);color:var(--body-color);font-family:var(--bs-body-font-family);line-height:var(--bs-body-line-height);transition:background-color 0.3s,color 0.3s;font-size:var(--bs-body-font-size)}
        h1,h2,h3,h4,h5,h6{color:var(--heading-color)}
        .text-muted{color:var(--muted-color) !important}
        .text-primary{color:var(--primary) !important}
        .card{background-color:var(--card-bg);border-color:var(--card-border);transition:background-color 0.3s,border-color 0.3s;border-radius:0.375rem;box-shadow:0 2px 8px rgba(0,0,0,0.05)}
        .modal-content,.dropdown-menu{background-color:var(--card-bg);border-color:var(--card-border);box-shadow:0 0.5rem 1.5rem rgba(0,0,0,0.2)}
        .modal-header,.modal-footer{border-color:var(--card-border)}
        .btn-primary{--bs-btn-bg:var(--primary);--bs-btn-border-color:var(--primary);--bs-btn-color:#fff;--bs-btn-hover-bg:color-mix(in srgb,var(--primary) 85%,black);--bs-btn-hover-border-color:color-mix(in srgb,var(--primary) 85%,black)}
        .btn-success{--bs-btn-bg:var(--primary);--bs-btn-border-color:var(--primary);--bs-btn-color:#fff;--bs-btn-hover-bg:color-mix(in srgb,var(--primary) 85%,black);--bs-btn-hover-border-color:color-mix(in srgb,var(--primary) 85%,black)}
        .btn-outline-success{--bs-btn-color:var(--primary);--bs-btn-border-color:var(--primary);--bs-btn-hover-bg:var(--primary);--bs-btn-hover-border-color:var(--primary);--bs-btn-hover-color:#fff}
        .form-control,.form-select{background-color:var(--card-bg);color:var(--body-color);border-color:var(--card-border)}
        .form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 0.25rem rgba(var(--primary-rgb),0.25)}
        .input-group-text,.dropdown-item:hover,#material-browser-list .list-group-item:hover{background-color:var(--tertiary-bg)}
        .table{--bs-table-bg:var(--card-bg);--bs-table-color:var(--body-color);--bs-table-border-color:var(--card-border);--bs-table-striped-bg:var(--tertiary-bg);--bs-table-hover-bg:color-mix(in srgb,var(--tertiary-bg) 80%,black)}
        .material-row{background-color:#f8f9fa;border:1px solid #dee2e6;padding:1rem;border-radius:0.375rem;margin-bottom:0.75rem}
        .material-row .change-material-btn{background-color:var(--card-bg) !important;border:1px solid var(--card-border) !important;text-align:left}
        .material-row .change-material-btn:hover{border-color:rgba(var(--primary-rgb),0.5) !important}
        .equivalent-item {background-color: #fff;padding: 1rem 0.5rem;border-radius: 0.375rem;border: 1px solid var(--card-border);height: 100%;display: flex;flex-direction: column;justify-content: center;align-items: center;transition: transform 0.2s ease, box-shadow 0.2s ease;}
        .equivalent-item:hover { transform: translateY(-3px); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1); }
        .equivalent-item .icon { font-size: 2rem; color: var(--primary); margin-bottom: 0.75rem; }
        .equivalent-item .value { font-size: 1.5rem; font-weight: bold; line-height: 1.2; }
        .equivalent-item .label { font-size: 0.8rem; color: var(--muted-color); }
        .interpretation-icon{cursor:pointer;opacity:0.6;transition:opacity 0.2s}
        .interpretation-icon:hover{opacity:1}
        .main-layout{height:calc(100vh - var(--header-height))}
        #control-panel-container{width:var(--sidebar-width);flex-basis:var(--sidebar-width);flex-shrink:0;transition:all 0.3s ease-in-out;height:100%;overflow-y:auto;padding:0.05rem}
        #main-content-container{flex-grow:1;height:100%;overflow-y:auto;padding:1.25rem;min-width:0}
        body.sidebar-collapsed #control-panel-container{width:0;min-width:0;flex-basis:0;padding:1.25rem 0;border-right:none !important;overflow:hidden}
        #sidebar-toggle-btn{position:fixed;top:54px;left:var(--sidebar-width);transform:translateX(-50%);z-index:1050;width:36px;height:36px;border-radius:50%;border:1px solid var(--card-border);background-color:var(--card-bg);box-shadow:0 2px 5px rgba(0,0,0,0.1);transition:all 0.3s ease-in-out}
        #sidebar-toggle-btn:hover{background-color:var(--primary);color:white}
        body.sidebar-collapsed #sidebar-toggle-btn{left:0;transform:translateX(50%)}
        body.sidebar-collapsed #sidebar-toggle-btn .icon-expanded{display:none}
        body:not(.sidebar-collapsed) #sidebar-toggle-btn .icon-collapsed{display:none}
        #interpretationModal .modal-header{background-color:var(--tertiary-bg);border-bottom:2px solid var(--primary)}
        #interpretation-body .interp-section{margin-bottom:1.75rem;padding-left:1.25rem;border-left:4px solid rgba(var(--primary-rgb),0.3)}
        #interpretation-body h5{font-weight:600;color:var(--heading-color);margin-bottom:0.75rem;display:flex;align-items:center}
        .highlight-term{background-color:rgba(var(--primary-rgb),0.1);color:color-mix(in srgb,var(--primary) 90%,black);padding:0.1em 0.4em;border-radius:0.25rem;font-weight:600}
        .formula{background-color:var(--tertiary-bg);border:1px solid var(--card-border);padding:1rem;border-radius:0.25rem;font-family:monospace,"Courier New",Courier;font-size:0.9em;word-wrap:break-word;white-space:pre-wrap}
        @media (max-width:991.98px){.main-layout{flex-direction:column;height:auto}
            #control-panel-container,body.sidebar-collapsed #control-panel-container{width:100%;flex-basis:auto;height:auto;min-width:100%;border-right:none !important;border-bottom:1px solid var(--card-border);padding:0}
            #main-content-container{width:100%;padding:1rem}
            #sidebar-toggle-btn{display:none}
            .control-panel-accordion .accordion-button{background-color:var(--tertiary-bg);color:var(--heading-color);font-weight:bold}
            .control-panel-accordion .accordion-button:not(.collapsed){background-color:var(--primary);color:white}
            .control-panel-accordion .accordion-button:focus{box-shadow:none}
            .control-panel-accordion .accordion-body{padding:1.25rem}
        }
        .leaflet-legend{padding:8px 12px;background:rgba(255,255,255,0.85);box-shadow:0 0 15px rgba(0,0,0,0.2);border-radius:5px;border:1px solid #ddd;line-height:1.5;color:#333;max-height:150px;overflow-y:auto}
        .leaflet-legend h6{margin-top:0;margin-bottom:5px;font-size:0.9rem;font-weight:bold}
        .leaflet-legend .legend-item{display:flex;align-items:center;margin-bottom:3px}
        .leaflet-legend .legend-color-box{width:18px;height:18px;margin-right:8px;border:1px solid rgba(0,0,0,0.2)}
        .pie-marker-svg{background:transparent;border:none;filter:drop-shadow(2px 2px 3px rgba(0,0,0,0.5));transition:transform 0.2s ease-in-out}
        .pie-marker-svg:hover{transform:scale(1.2)}
        .pie-marker-icon{transition:opacity 0.3s ease-in-out,z-index 0s 0.3s;z-index:100}
        .pie-marker-icon .pie-marker-svg{transition:transform 0.3s cubic-bezier(0.175,0.885,0.32,1.275)}
        .marker-highlight{z-index:1001 !important;transition-delay:0s}
        .marker-highlight .pie-marker-svg{transform:scale(1.4)}
        .marker-fade{opacity:0.3}
        .preset-loader-wrapper{border:1px dashed var(--card-border);background-color:var(--tertiary-bg);padding:1rem;border-radius:0.375rem}
        .ai-tooltip{--bs-tooltip-max-width:400px;--bs-tooltip-bg:var(--card-bg);--bs-tooltip-color:var(--body-color);--bs-tooltip-opacity:1;box-shadow:0 0.5rem 1rem rgba(0,0,0,0.15);border:1px solid var(--card-border)}
        .comparison-bar{display:flex;align-items:center;font-size:0.75rem;margin-bottom:0.35rem}
        .comparison-bar .label{width:80px;flex-shrink:0;text-align:left}
        .comparison-bar .bars{flex-grow:1;height:18px;background-color:var(--tertiary-bg);position:relative;border-radius:3px;overflow:hidden}
        .comparison-bar .bar{position:absolute;top:0;left:0;height:100%}
        .comparison-bar .bar-before{background-color:var(--secondary);opacity:0.4;z-index:1}
        .comparison-bar .bar-after{background-color:var(--primary);z-index:2}
        .comparison-bar .value{width:90px;flex-shrink:0;text-align:left}
        .lens-overlay{position:absolute;top:0;left:0;width:100%;height:100%;background-color:rgba(248,249,250,0.85);z-index:10;opacity:0;transition:opacity 0.3s ease-in-out;pointer-events:none}
        .lens-highlight{position:relative;z-index:20 !important;box-shadow:0 0 0 3px var(--primary),0 0 25px rgba(var(--primary-rgb),0.5) !important;transition:box-shadow 0.3s ease-in-out}
        .lens-highlight .lens-overlay{opacity:0 !important}
        .lens-active .results-panel-wrapper > .row > [class*="col-"] > .card,.lens-active .results-panel-wrapper > .row > [class*="col-"] > div:not(#holistic-analysis-container) > .card{position:relative}
        .lens-active .results-panel-wrapper .lens-overlay{opacity:1;pointer-events:auto}
        .lens-annotation{position:absolute;top:-15px;left:50%;transform:translateX(-50%);background-color:var(--primary);color:white;padding:0.3rem 0.8rem;border-radius:2rem;font-size:0.85rem;font-weight:bold;z-index:30;white-space:nowrap;animation:fadeInDown 0.5s}
        .btn.lens-highlight,.btn-group.lens-highlight{box-shadow:0 0 0 3px var(--primary),0 0 15px rgba(var(--primary-rgb),0.6);z-index:20;position:relative}
        .btn-group > .btn-check:checked + .btn-outline-secondary,
        .btn-group > .btn-check:not(:checked) + .btn-outline-secondary:hover {background-color: var(--primary);color: #fff;border-color: var(--primary);}
        @keyframes fadeInDown{from{opacity:0;transform:translate(-50%,-10px)}
            to{opacity:1;transform:translate(-50%,0)}
        }
        /* Shepherd.js 的客製化樣式 */
        .shepherd-element {
            box-shadow: 0 0 20px rgba(0,0,0,0.3);
            border-radius: 8px;
        }
        .shepherd-header {
            background-color: var(--primary);
            color: white;
            padding: 0.75rem 1.25rem;
        }
        .shepherd-text {
            padding: 1rem 1.25rem;
            color: var(--body-color);
        }
        .shepherd-button {
            background-color: var(--primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            margin-right: 0.5rem;
            transition: background-color 0.2s;
        }
        .shepherd-button:hover {
            background-color: color-mix(in srgb, var(--primary) 85%, black);
        }
        .shepherd-button.shepherd-button-secondary {
            background-color: var(--tertiary-bg);
            color: var(--tertiary-color);
        }
        /* AI 生成內容的客製化樣式 */
        #ai-narrative-modal-body h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
            margin-top: 1rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--card-border);
        }
        #ai-narrative-modal-body ul {
            padding-left: 1.5rem;
        }
        #ai-narrative-modal-body li {
            margin-bottom: 0.5rem;
        }
        .cell-changed {
            background-color: rgba(var(--bs-warning-rgb), 0.15) !important;
            font-weight: bold;
        }

        /* V9.3 - 桑基圖分析儀佈局樣式 (洞察面板預設顯示) */
        .sankey-analyzer-card .card-body {
            display: flex;
            flex-direction: column;
            height: 550px;
            padding: 0;
        }
        .sankey-kpi-bar {
            display: flex;
            justify-content: space-around;
            align-items: center;
            background-color: var(--bs-tertiary-bg);
            flex-shrink: 0;
        }
        .kpi-item { text-align: center; }
        .kpi-item .kpi-label { font-size: 0.8rem; color: var(--muted-color); }
        .kpi-item .kpi-value { font-size: 1.75rem; font-weight: 700; color: var(--primary); line-height: 1.2; }

        .sankey-content-wrapper {
            display: flex;
            flex-grow: 1;
            position: relative;
            overflow: hidden;
        }
        .sankey-chart-main {
            flex-grow: 1;
            transition: width 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
            height: 100%;
        }
        .sankey-detail-panel {
            width: 350px;
            height: 100%;
            background-color: var(--card-bg);
            border-left: 1px solid var(--card-border);
            flex-shrink: 0;
            transition: margin-right 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
            overflow-y: auto;
        }
        .sankey-detail-panel.is-closed {
            margin-right: -350px; /* 將面板向右移出畫面 */
        }
        #sankey-show-detail-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 20;
        }


        /* 手機再小一點（可選） */
        @media (max-width: 1920px) {
            .badge {
                font-size: .72rem;
            }
        }
        .sdg-icons-container {
            gap: 4px;
        }
        .sdg-icon {
            height: 24px;
            width: 24px;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        .sdg-icon:hover {
            transform: scale(1.2);
        }
        .process-row {
            background-color: #f0f8ff; /* 淡藍色背景 */
            border: 1px solid #cce6ff; /* 較淺的藍色邊框 */
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 0.75rem;
        }
        .process-row .ts-control {
            background-color: var(--card-bg) !important;
        }
        .process-row .ts-control {
            background-color: var(--card-bg) !important;
        }

        /* 【全新CSS規則】為製程的選擇按鈕加上外框與樣式 */
        .process-row .change-process-btn {
            background-color: var(--card-bg) !important;
            border: 1px solid var(--card-border) !important;
            text-align: left;
        }
        .process-row .change-process-btn:hover {
            border-color: rgba(var(--primary-rgb), 0.5) !important;
        }
        /* 【V12.8 - 緊湊化 UI 修正】 */
        /* 減小組件卡片之間的垂直間距 */
        .material-row, .process-row {
            margin-bottom: 0.5rem; /* 原為 0.75rem */
            padding: 0.8rem;       /* 原為 1rem */
        }

        /* 微調卡片內部元素的間距，讓整體更協調 */
        .material-row .row, .process-row .row {
            --bs-gutter-y: 0.6rem; /* 減小行與行之間的間距, g-2 預設為 0.5rem */
        }

        .material-row > div:not(:last-child),
        .process-row > div:not(:last-child) {
            margin-bottom: 0.6rem !important; /* 原為 mb-2 (0.5rem)，稍微拉開一點點呼吸空間 */
        }

        /* 特別針對製程的數量與作用於欄位，讓它們更貼合 */
        .process-row .row.g-2 {
            --bs-gutter-y: 0.5rem; /* 保持這兩個欄位的緊湊感 */
        }
        @media (max-width: 991.98px) {
            /* 讓桌機版那塊在手機也顯示 */
            .d-none.d-lg-block {
                display: block !important;
            }

            /* 隱藏目前沒填內容的手機版空白 Accordion */
            .d-lg-none {
                display: none !important;
            }

            /* 左側容器在手機改為滿版並移除右邊框線 */
            #control-panel-container {
                width: 100% !important;
                border-right: 0 !important;
                border-bottom: 1px solid var(--card-border);
                max-height: none;
            }

            /* 主內容也維持滿版 */
            #main-content-container {
                width: 100% !important;
            }

            /* 若需要保留側欄切換鈕，行動版顯示並調整位置 */
            #sidebar-toggle-btn {
                display: block !important;
                left: .75rem;
                top: calc(var(--header-height, 58px) + .5rem);
                transform: none;
                z-index: 1031; /* 確保在上層 */
            }
        }

    </style>
</head>
<body>
<div id="loading-overlay" style="display: none; position: fixed; top:0; left:0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1070; align-items: center; justify-content: center; color: white; flex-direction: column;"><div class="spinner-border mb-3" role="status"></div><span id="loading-text">處理中...</span></div>
<div class="container-fluid">

    <div class="container-fluid">
        <header class="d-flex align-items-center py-2 px-3 border-bottom sticky-top" style="background-color: var(--body-bg);">
            <div class="d-flex align-items-center me-auto">
                <h3 class="mb-0"><i class="fa-solid fa-leaf text-primary"></i> ESG 永續材料決策系統</h3>
            </div>

            <div class="d-none d-md-flex align-items-center">
        <span class="navbar-text me-3">
            歡迎, <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>
        </span>
                <?php if ($_SESSION['username'] === 'superadmin'): ?>
                    <a href="manage_materials.php" class="btn btn-outline-secondary btn-sm me-3" title="物料庫管理"><i class="fas fa-database"></i></a>
                    <button class="btn btn-outline-secondary btn-sm me-3" type="button" data-bs-toggle="modal" data-bs-target="#adminSettingsModal" title="系統管理員設定">
                        <i class="fas fa-cog"></i>
                    </button>
                <?php endif; ?>
                <a href="?action=logout" class="btn btn-outline-secondary btn-sm me-3" title="登出">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
                <a href="primary_data_manager.php" class="btn btn-outline-warning btn-sm me-3"><i class="fas fa-ruler-combined me-2"></i><span class="d-none d-lg-inline">一級數據中心</span></a>
                <a href="corporate.php" class="btn btn-outline-success btn-sm me-3"><i class="fas fa-building me-2"></i><span class="d-none d-lg-inline">企業碳盤查</span></a>
                <a href="illustrate.php" class="btn btn-primary btn-sm me-3 pe-1"><i class="fa-solid fa-calculator me-2"></i><span class="d-none d-lg-inline">專家指南</span></a>
                <a href="#" class="btn btn-outline-info btn-sm me-3" data-bs-toggle="modal" data-bs-target="#extendedAppsModal"><i class="fas fa-puzzle-piece me-2"></i><span class="d-none d-lg-inline">應用中心</span></a>
                <select class="form-select form-select-sm" id="theme-selector" style="width: auto;">
                    <option value="light-green">預設亮色</option>
                    <option value="dark-green">預設深色</option>
                    <optgroup label="莫蘭迪色系">
                        <option value="morandi-apricot">莫蘭迪暖杏</option>
                        <option value="morandi-pink">莫蘭迪粉</option>
                        <option value="morandi-blue">莫蘭迪藍</option>
                        <option value="morandi-green">莫蘭迪綠</option>
                    </optgroup>
                    <optgroup label="情境主題">
                        <option value="ocean-blue">海洋藍</option>
                        <option value="sunset-orange">日落橙</option>
                        <option value="earth-tones">大地色</option>
                        <option value="sakura-dream">櫻花之夢 (亮)</option>
                        <option value="lush-forest">茂密森林 (深)</option>
                        <option value="cyberpunk-neon">賽博龐克 (深)</option>
                        <option value="royal-purple">皇家紫 (深)</option>
                    </optgroup>
                    <option value="grayscale">簡約灰</option>
                </select>
            </div>

            <button class="btn btn-primary d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenuOffcanvas" aria-controls="mobileMenuOffcanvas">
                <i class="fas fa-bars"></i>
            </button>
        </header>
        <div class="offcanvas offcanvas-end" tabindex="-1" id="mobileMenuOffcanvas" aria-labelledby="mobileMenuOffcanvasLabel">
            <div class="offcanvas-header">
                <h5 class="offcanvas-title" id="mobileMenuOffcanvasLabel">選單</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body">
                <div class="d-grid gap-3">
                    <div class="text-center">
                        <span class="navbar-text">歡迎, <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></span>
                    </div>

                    <a href="primary_data_manager.php" class="btn btn-outline-warning w-100"><i class="fas fa-ruler-combined fa-fw me-2"></i>一級數據中心</a>
                    <a href="corporate.php" class="btn btn-outline-success w-100"><i class="fas fa-building fa-fw me-2"></i>企業碳盤查</a>
                    <a href="illustrate.php" class="btn btn-outline-primary w-100"><i class="fa-solid fa-calculator fa-fw me-2"></i>專家指南</a>
                    <a href="#" class="btn btn-outline-info btn-sm me-3" data-bs-toggle="modal" data-bs-target="#extendedAppsModal"><i class="fas fa-sitemap me-2"></i>應用中心</a>

                    <?php if ($_SESSION['username'] === 'superadmin'): ?>
                        <a href="manage_materials.php" class="btn btn-outline-secondary w-100"><i class="fas fa-database fa-fw me-2"></i>物料庫管理</a>
                        <button class="btn btn-outline-secondary w-100" type="button" data-bs-toggle="modal" data-bs-target="#adminSettingsModal"><i class="fas fa-cog fa-fw me-2"></i>系統管理員設定</button>
                    <?php endif; ?>

                    <hr>

                    <div>
                        <label for="mobile-theme-selector" class="form-label">色彩主題</label>
                        <select class="form-select" id="mobile-theme-selector">
                            <option value="light-green">預設亮色</option>
                            <option value="dark-green">預設深色</option>
                            <optgroup label="莫蘭迪色系">
                                <option value="morandi-apricot">莫蘭迪暖杏</option>
                                <option value="morandi-pink">莫蘭迪粉</option>
                                <option value="morandi-blue">莫蘭迪藍</option>
                                <option value="morandi-green">莫蘭迪綠</option>
                            </optgroup>
                            <optgroup label="情境主題">
                                <option value="ocean-blue">海洋藍</option>
                                <option value="sunset-orange">日落橙</option>
                                <option value="earth-tones">大地色</option>
                                <option value="sakura-dream">櫻花之夢 (亮)</option>
                                <option value="lush-forest">茂密森林 (深)</option>
                                <option value="cyberpunk-neon">賽博龐克 (深)</option>
                                <option value="royal-purple">皇家紫 (深)</option>
                            </optgroup>
                            <option value="grayscale">簡約灰</option>
                        </select>
                    </div>

                    <a href="?action=logout" class="btn btn-outline-danger w-100"><i class="fas fa-sign-out-alt fa-fw me-2"></i>登出</a>
                </div>
            </div>
        </div>

        <div class="d-flex main-layout">
            <div id="control-panel-container" class="control-panel border-end">
                <div class="d-none d-lg-block">
                    <form id="calculatorForm" class="p-3">
                        <div class="card">
                            <div class="card-header fw-bold">專案與版本</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label fw-bold d-flex align-items-center">1. 選擇組織
                                        <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" title="組織" data-bs-content="專案是依附於組織底下的。請先選擇或建立一個組織，才能開始管理專案。"></i>
                                    </label>
                                    <div class="input-group">
                                        <select id="project-organization-selector" class="form-select"></select>
                                        <button class="btn btn-outline-success" type="button" id="create-new-org-btn" title="建立新組織">+</button>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold d-flex align-items-center">2. 選擇專案
                                        <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" title="專案" data-bs-content="您可以載入既有專案，或在下拉選單中選擇「建立一個新專案」。所有分析版本都會儲存在專案底下。"></i>
                                    </label>
                                    <div class="input-group">
                                        <select class="form-select" id="projectSelector" disabled=""></select>
                                        <button class="btn btn-outline-secondary" type="button" id="reloadProjectsBtn" title="重新整理專案列表"><i class="fas fa-sync-alt"></i></button>
                                    </div>
                                    <input type="text" class="form-control mt-2" id="newProjectName" placeholder="請輸入新專案名稱..." style="display: none;">
                                </div>
                                <div id="migration-prompt" class="alert alert-warning p-2 mt-2 small" style="display: none;">
                                    此為舊專案，請為它指派一個所屬組織...
                                    <div class="d-grid mt-2"><button class="btn btn-warning btn-sm" id="assign-org-btn">立即指派組織</button></div>
                                </div>
                                <div class="mb-3">
                                    <label for="versionName" class="form-label fw-bold d-flex align-items-center">版本 / 報告名稱
                                        <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" title="版本名稱" data-bs-content="為這次的分析命名，例如：「V2 - 採用再生PET蓋」。一個有意義的名稱有助於您後續進行版本比較。"></i>
                                    </label>
                                    <input type="text" class="form-control" id="versionName" value="預設產品：ABS 原子筆" placeholder="例如：V2 - 採用再生PET蓋">
                                </div>
                                <hr>

                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0">產品物料清單 (BOM)</h5>
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-primary" id="ai-vision-btn">
                                            <i class="fas fa-camera-alt me-2"></i>AI 視覺辨識
                                        </button>
                                        <input type="file" id="ai-vision-input" accept="image/*" capture="environment" style="display: none;">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="open-preset-browser-btn">
                                            <i class="fas fa-magic me-2"></i>載入預設產品樣板
                                        </button>
                                    </div>
                                </div>
                                <div id="materials-list-container"></div>
                                <div class="btn-group w-100" role="group">
                                    <button type="button" class="btn btn-outline-success" id="add-material-btn"><i class="fas fa-plus"></i> 新增物料</button>
                                    <button type="button" class="btn btn-outline-info" id="add-process-btn"><i class="fas fa-cogs"></i> 新增製程</button>
                                    <button type="button" class="btn btn-outline-danger" id="clear-list-btn"><i class="fas fa-trash"></i> 清除全部</button>
                                </div>
                                <hr>

                                <div id="eol-card" class="mb-3">
                                    <p class="fw-bold mb-2 d-flex align-items-center">生命週期終端 (EOL) 情境 <small class="text-muted">(選填)</small>
                                        <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-html="true" title="生命週期終端 (EOL) 解析" data-bs-content="這裡模擬產品在廢棄後如何被處理...三者總和必須為100%。"></i>
                                    </p>
                                    <div class="row g-2">
                                        <div class="col-4"><div class="input-group input-group-sm"><span class="input-group-text">回收</span><input type="number" class="form-control eol-input" id="eolRecycle" value="100" min="0" max="100"><span class="input-group-text">%</span></div></div>
                                        <div class="col-4"><div class="input-group input-group-sm"><span class="input-group-text">焚化</span><input type="number" class="form-control eol-input" id="eolIncinerate" value="0" min="0" max="100"><span class="input-group-text">%</span></div></div>
                                        <div class="col-4"><div class="input-group input-group-sm"><span class="input-group-text">掩埋</span><input type="number" class="form-control eol-input" id="eolLandfill" value="0" min="0" max="100"><span class="input-group-text">%</span></div></div>
                                    </div>
                                    <div id="eol-warning" class="text-danger small mt-2 d-none fw-bold">比例總和不等於 100%！</div>
                                </div>

                                <div id="transport-phase-card" class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <p class="fw-bold mb-2 d-flex align-items-center">運輸階段 (Transportation)
                                            <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-html="true" title="運輸階段解析" data-bs-content="此階段模擬產品從製造地到消費者手中的運輸過程所產生的碳排放。您可以選擇一個預設的『全局運輸路徑』，或為BOM中的每個物料『編輯單項路徑』以進行更精細的模擬。"></i>
                                        </p>
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="enableTransportPhase"><label class="form-check-label small" for="enableTransportPhase">啟用分析</label></div>
                                    </div>
                                    <div id="transport-phase-inputs-container" style="display: none;">
                                        <div class="input-group input-group-sm"><span class="input-group-text">全局運輸路徑</span><select id="globalTransportRoute" class="form-select form-select-sm"></select></div>
                                        <div class="d-grid mt-2"><button type="button" class="btn btn-outline-secondary btn-sm" id="edit-transport-overrides-btn"><i class="fas fa-edit me-2"></i>編輯單項路徑</button></div>
                                    </div>
                                </div>

                                <div id="use-phase-card" class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <p class="fw-bold mb-2 d-flex align-items-center">使用階段 (Use Phase)
                                            <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-html="true" title="使用階段解析" data-bs-content="此階段模擬產品在消費者使用過程中，因消耗能源（如電力）或水資源所產生的環境衝擊。<b>簡易模式</b>提供預設情境，<b>專家模式</b>則允許您手動輸入詳細參數。"></i>
                                        </p>
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="enableUsePhase"><label class="form-check-label small" for="enableUsePhase">啟用分析</label></div>
                                    </div>
                                    <div id="use-phase-inputs-container" style="opacity: 0.6;">
                                        <div class="btn-group w-100 mb-3" role="group">
                                            <input type="radio" class="btn-check" name="usePhaseMode" id="usePhaseSimple" value="simple" checked="">
                                            <label class="btn btn-outline-primary" for="usePhaseSimple"><i class="fas fa-magic me-2"></i>簡易模式</label>
                                            <input type="radio" class="btn-check" name="usePhaseMode" id="usePhaseExpert" value="expert">
                                            <label class="btn btn-outline-primary" for="usePhaseExpert"><i class="fas fa-user-cog me-2"></i>專家模式</label>
                                        </div>
                                        <div id="simple-mode-wrapper" style="">
                                            <div class="mb-2">
                                                <label class="form-label small">產品情境</label>
                                                <select id="usePhaseScenarioSelector" class="form-select form-select-sm use-phase-input"></select>
                                            </div>
                                            <div id="implicit-bom-warning" class="alert alert-warning p-2 small" style="display: none;"></div>
                                            <div class="mb-2">
                                                <label class="form-label small">使用頻率</label>
                                                <div class="d-flex align-items-center">
                                                    <span class="small text-muted me-2">輕度</span>
                                                    <input type="range" class="form-range use-phase-input" id="usageFrequencySlider" min="0" max="100" value="50">
                                                    <span class="small text-muted ms-2">重度</span>
                                                </div>
                                            </div>
                                            <a href="#" id="toggle-simple-details" class="d-block text-center small">顯示/隱藏參數細節 <i class="fas fa-chevron-down fa-xs"></i></a>
                                            <div id="simple-mode-details" class="mt-2" style="display: none;"></div>
                                        </div>
                                        <div id="expert-mode-wrapper" style="display: none;">
                                            <div class="row g-2">
                                                <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">產品壽命</span><input type="number" class="form-control use-phase-input" id="usePhaseLifespan" value="5"><span class="input-group-text">年</span></div></div>
                                                <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">年耗電量</span><input type="number" class="form-control use-phase-input" id="usePhaseKwh" value="0"><span class="input-group-text">kWh</span></div></div>
                                                <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">電網區域</span><select id="usePhaseGrid" class="form-select form-select-sm use-phase-input"></select></div></div>
                                                <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">年耗水量</span><input type="number" class="form-control use-phase-input" id="usePhaseWater" value="0"><span class="input-group-text">L</span></div></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <hr>

                                <div class="mb-3">
                                    <label for="productionQuantity" class="form-label d-flex align-items-center">生產數量 <small class="text-muted">(選填)</small>
                                        <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" title="生產數量" data-bs-content="輸入您的預估年產量或總生產批次量，系統會將單件產品的衝擊乘以這個數量，以計算總體效益。"></i>
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="productionQuantity" value="1" min="1">
                                        <span class="input-group-text">件</span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label d-flex align-items-center">商業價值定位 <small class="text-muted">(選填)</small>
                                        <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" title="商業價值定位" data-bs-content="輸入財務數據後，系統將自動為您產生「商業決策儀表板」，將永續效益與財務指標連結。"></i>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">單件售價 (TWD)</span>
                                        <input type="number" class="form-control" id="sellingPriceInput" placeholder="例如：1500" min="0" step="0.01">
                                    </div>
                                    <div class="input-group mt-2">
                                        <span class="input-group-text">單件製造成本</span>
                                        <input type="number" class="form-control" id="manufacturingCostInput" placeholder="選填" min="0" step="0.01">
                                    </div>
                                    <div class="input-group mt-2">
                                        <span class="input-group-text">單件管銷/其他</span>
                                        <input type="number" class="form-control" id="sgaCostInput" placeholder="選填" min="0" step="0.01">
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold">單件產品總重:</span>
                                    <span class="fs-5 fw-bolder text-primary" id="total-weight-display">0.000 kg</span>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" id="calculate-btn" class="btn btn-primary btn-lg"><i class="fas fa-chart-pie"></i> 分析效益</button>
                                    <div class="btn-group"><button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#materialsModal"><i class="fas fa-database"></i> 物料庫</button><button type="button" class="btn btn-outline-secondary" id="open-history-btn" data-bs-toggle="modal" data-bs-target="#historyModal"><i class="fas fa-history"></i> 分析歷程與比較</button></div>
                                </div>

                            </div>
                        </div>

                    </form>
                </div>
                <div class="d-lg-none">
                    <div class="accordion control-panel-accordion" id="controlPanelAccordionMobile">
                    </div>
                </div>
            </div>

            <div id="main-content-container">
                <div class="results-panel-wrapper" id="results-panel-wrapper" style="display: none;">
                </div>
                <div class="d-flex align-items-center justify-content-center text-muted h-100" id="initial-message">
                    <div><h2 class="text-center">請在左側加入物料以開始分析</h2><p class="text-center">結果與圖表將會顯示於此處</p></div>
                </div>
            </div>
        </div>

        <button class="btn btn-outline-secondary" type="button" id="sidebar-toggle-btn" title="切換側邊欄">
            <span class="icon-expanded"><i class="fas fa-chevron-left"></i></span>
            <span class="icon-collapsed"><i class="fas fa-chevron-right"></i></span>
        </button>
    </div>
</div>

<footer class="text-center small text-muted mt-4 py-3 border-top" id="main-footer"></footer>

<div class="modal fade" id="adminSettingsModal" tabindex="-1" aria-labelledby="adminSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="adminSettingsModalLabel"><i class="fas fa-user-shield me-2"></i> 系統管理員設定</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6 class="fw-bold">系統設定</h6>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="allowRegistrationSwitch">
                    <label class="form-check-label" for="allowRegistrationSwitch">開放新使用者註冊</label>
                </div>
                <hr>
                <h6 class="fw-bold">公司資訊 (用於報告頁尾)</h6>
                <div class="mb-3">
                    <label for="companyNameInput" class="form-label">公司名稱</label>
                    <input type="text" class="form-control" id="companyNameInput" placeholder="請輸入您的公司名稱">
                </div>
                <div class="mb-3">
                    <label for="companyUrlInput" class="form-label">公司網址</label>
                    <input type="url" class="form-control" id="companyUrlInput" placeholder="https://example.com">
                </div>

                <hr>
                <h6 class="fw-bold">數位簽章密鑰 (DPP Secret Key)</h6>
                <p class="small text-muted">此密鑰為系統安全核心，用於確保報告不被竄改。若需重新產生，請點擊下方按鈕。</p>
                <div class="input-group">
                    <input type="text" class="form-control" id="dppSecretKeyInput" readonly placeholder="密鑰將顯示於此">
                    <button class="btn btn-warning" type="button" id="generateDppKeyBtn" title="產生一組新的高強度隨機密鑰">
                        <i class="fas fa-sync-alt"></i> 產生新密鑰
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="saveAdminSettingsBtn">儲存設定</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="materialBrowserModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">選擇物料</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
                <div class="mb-3"><input type="search" class="form-control" id="material-browser-search" placeholder="搜尋物料名稱或 KEY..."></div>
                <div id="material-browser-list"></div>
            </div></div></div></div>
<div class="modal fade" id="materialsModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-database me-2"></i>物料庫總覽</h5>
                <div class="ms-4 w-50">
                    <input type="search" class="form-control" id="material-library-search" placeholder="搜尋物料名稱、分類或來源...">
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm" id="materials-library-table">
                        <thead class="table-light sticky-top">
                        <tr>
                            <th style="width: 2%;"></th>
                            <th class="sortable" data-sort="name">名稱 (KEY)</th>
                            <th class="sortable" data-sort="category">分類</th>
                            <th class="sortable" data-sort="source">主要數據來源</th>
                            <th class="text-end sortable" data-sort="virgin_co2e_kg">原生料碳排 (kg)</th>
                            <th class="text-end sortable" data-sort="recycled_co2e_kg">再生料碳排 (kg)</th>
                            <th class="text-end sortable" data-sort="cost_per_kg">參考成本 ($/kg)</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php

                        $field_labels = [
                            'name' => '名稱', 'category' => '分類', 'data_source' => '主要來源', 'cost_per_kg' => '參考成本 ($/kg)',
                            'virgin_co2e_kg' => '原生料碳排 (kg CO₂e)', 'virgin_energy_mj_kg' => '原生料能耗 (MJ)',
                            'virgin_water_l_kg' => '原生料水耗 (L)', 'virgin_adp_kgsbe' => '原生料資源消耗 (kg Sb eq)',
                            'recycled_co2e_kg' => '再生料碳排 (kg CO₂e)', 'recycled_energy_mj_kg' => '再生料能耗 (MJ)',
                            'recycled_water_l_kg' => '再生料水耗 (L)', 'recycled_adp_kgsbe' => '再生料資源消耗 (kg Sb eq)',
                            'acidification_kg_so2e' => '酸化潛力 (kg SO₂e)', 'eutrophication_kg_po4e' => '優養化潛力 (kg PO₄e)',
                            'ozone_depletion_kg_cfc11e' => '臭氧層破壞 (kg CFC-11e)', 'photochemical_ozone_kg_nmvoce' => '光化學煙霧 (kg NMVOCe)',
                            'sources' => '詳細來源',
                            'social_risk_score' => '社會風險評分', 'supply_chain_transparency' => '供應鏈透明度',
                            'known_risks' => '已知風險 (S)', 'certifications' => '相關認證 (S)',
                            'governance_risk_score' => '治理風險評分', 'identified_risks' => '已知風險 (G)',
                            'positive_attributes' => '正面實踐 (G)',
                            'biogenic_carbon_content_kg' => '生物源碳含量 (kg)',
                            'is_critical_raw_material' => '是否為關鍵原料',
                            'recyclability_rate_pct' => '可回收率 (%)',
                            'recycled_content_certification' => '回收成分認證',
                            'labor_practices_risk_score' => '勞工實務風險',
                            'health_safety_risk_score' => '職業健康安全風險',
                            'living_wage_gap_pct' => '生活工資差距 (%)',
                            'gender_pay_gap_pct' => '性別薪酬差距 (%)',
                            'supplier_audit_status' => '供應商稽核狀態',
                            'last_audit_date' => '上次稽核日期',
                            'is_high_child_labor_risk' => '高童工風險',
                            'business_ethics_risk_score' => '商業道德風險',
                            'transparency_risk_score' => '透明度風險',
                            'anti_corruption_training_coverage_pct' => '反貪腐訓練覆蓋率 (%)',
                            'whistleblower_reports_unresolved' => '未解決的吹哨者報告',
                            'is_from_sanctioned_country' => '來自受制裁國家'
                        ];

                        foreach ($all_materials as $m):
                            $sources = isset($m['sources']) ? json_decode($m['sources'], true) : [];
                            $primary_source = $sources['primary'] ?? $m['data_source'] ?? 'N/A';
                            ?>
                            <tr class="material-library-row" data-search-terms="<?= strtolower(htmlspecialchars($m['name'].' '.$m['key'].' '.$m['category'].' '.$primary_source)) ?>">
                                <td><i class="fas fa-chevron-right expand-details-btn" style="cursor: pointer;"></i></td>
                                <td>
                                    <strong><?= htmlspecialchars($m['name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($m['key']) ?></small>
                                </td>
                                <td><span class="badge bg-secondary bg-opacity-25 text-dark-emphasis"><?= htmlspecialchars($m['category'] ?? 'N/A') ?></span></td>
                                <td><small><?= htmlspecialchars($primary_source) ?></small></td>
                                <td class="text-end"><?= htmlspecialchars($m['virgin_co2e_kg'] ?? '') ?></td>
                                <td class="text-end"><?= htmlspecialchars($m['recycled_co2e_kg'] ?? '') ?></td>
                                <td class="text-end"><?= htmlspecialchars($m['cost_per_kg'] ?? '') ?></td>
                            </tr>
                            <tr class="material-details-row" style="display: none;">
                                <td colspan="7" class="p-0">
                                    <div class="p-3 bg-light-subtle">
                                        <div class="row g-3">
                                            <?php
                                            $fields_groups = [
                                                '核心指標' => ['name', 'category', 'cost_per_kg', 'sources', 'is_critical_raw_material'],
                                                '環境衝擊 (原生料)' => ['virgin_co2e_kg', 'virgin_energy_mj_kg', 'virgin_water_l_kg', 'virgin_adp_kgsbe', 'biogenic_carbon_content_kg'],
                                                '環境衝擊 (再生料)' => ['recycled_co2e_kg', 'recycled_energy_mj_kg', 'recycled_water_l_kg', 'recycled_adp_kgsbe'],
                                                '循環經濟指標' => ['recyclability_rate_pct', 'recycled_content_certification'],
                                                '社會責任 (S)' => ['social_risk_score', 'labor_practices_risk_score', 'health_safety_risk_score', 'is_high_child_labor_risk', 'living_wage_gap_pct', 'gender_pay_gap_pct', 'supply_chain_transparency', 'certifications', 'known_risks'],
                                                '企業治理 (G)' => ['governance_risk_score', 'business_ethics_risk_score', 'transparency_risk_score', 'is_from_sanctioned_country', 'supplier_audit_status', 'last_audit_date', 'anti_corruption_training_coverage_pct', 'whistleblower_reports_unresolved', 'positive_attributes', 'identified_risks'],
                                                '其他環境衝擊' => ['acidification_kg_so2e', 'eutrophication_kg_po4e', 'ozone_depletion_kg_cfc11e', 'photochemical_ozone_kg_nmvoce']
                                            ];
                                            foreach ($fields_groups as $title => $field_keys):
                                                ?>
                                                <div class="col-lg-4 col-md-6">
                                                    <h6><?= $title ?></h6>
                                                    <dl class="row mb-0">
                                                        <?php foreach ($field_keys as $field):
                                                            if (!isset($m[$field])) continue;
                                                            $display_value = $m[$field];
                                                            $label = $field_labels[$field] ?? $field;

                                                            if ($field === 'sources' && is_string($display_value)) {
                                                                $decoded_sources = json_decode($display_value, true);
                                                                $display_value = "主: " . ($decoded_sources['primary'] ?? 'N/A') . "<br>次: " . ($decoded_sources['secondary'] ?? 'N/A') . "<br>成本: " . ($decoded_sources['cost'] ?? 'N/A');
                                                            } else {
                                                                if (is_string($display_value)) {
                                                                    $display_value = htmlspecialchars($display_value);
                                                                }
                                                            }
                                                            ?>
                                                            <dt class="col-sm-6 text-muted small" style="word-break: break-all;"><?= $label ?></dt>
                                                            <dd class="col-sm-6">
                                                                <?php
                                                                if (in_array($field, ['social_risk_score', 'governance_risk_score', 'labor_practices_risk_score', 'health_safety_risk_score', 'business_ethics_risk_score', 'transparency_risk_score'])) {
                                                                    $score = (int)($m[$field] ?? 0);
                                                                    $color = 'success';
                                                                    if ($score >= 70) $color = 'danger';
                                                                    elseif ($score >= 40) $color = 'warning';
                                                                    echo "<span class='badge bg-{$color}'>{$score} / 100</span>";
                                                                } elseif (in_array($field, ['is_critical_raw_material', 'is_high_child_labor_risk', 'is_from_sanctioned_country'])) {
                                                                    echo $m[$field] ? '<span class="badge bg-danger">是</span>' : '<span class="badge bg-success">否</span>';
                                                                } elseif (in_array($field, ['known_risks', 'certifications', 'identified_risks', 'positive_attributes', 'recycled_content_certification'])) {
                                                                    $items = is_string($m[$field]) ? json_decode($m[$field], true) : ($m[$field] ?? []);
                                                                    if (empty($items)) { echo '<small class="text-muted">無</small>'; }
                                                                    else { $badge_color = (strpos($field, 'risk') !== false) ? 'bg-warning text-dark' : 'bg-success'; foreach ($items as $item) { echo '<span class="badge ' . $badge_color . ' me-1 mb-1">' . htmlspecialchars($item) . '</span>'; } }
                                                                } else {
                                                                    echo "<span>{$display_value}</span>"; // 移除了 editable class 和編輯圖示
                                                                }
                                                                ?>
                                                            </dd>
                                                        <?php endforeach; ?>
                                                    </dl>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <a href="manage_materials.php" target="_blank" class="btn btn-info me-auto">
                    <i class="fas fa-edit me-2"></i>前往物料管理中心
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">分析歷程管理與版本比較</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <tbody id="history-table-body">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div>
                    <button type="button" class="btn btn-success" id="compare-reports-btn" disabled>
                        <i class="fas fa-exchange-alt me-2"></i>比較選擇的項目 (0/2)
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="swap-comparison-btn" style="display: none;" title="交換基準(A)與比較(B)">
                        <i class="fas fa-retweet"></i>
                    </button>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-danger" id="clear-history-btn">
                        <i class="fas fa-exclamation-triangle me-2"></i>清空所有歷史記錄
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="embedModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">取得內嵌碼與說明</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>請複製下方的 HTML 程式碼，並將其貼到您的網站或部落格中。為使框架能自動調整高度，請務必將 <strong>iframe 與 script 兩段程式碼</strong>都貼上。</p>
                <label class="form-label fw-bold">1. Iframe 內嵌框架</label>
                <textarea class="form-control" id="embed-code-iframe" rows="3" readonly></textarea>
                <label class="form-label fw-bold mt-3">2. Script 自動高度腳本 (建議放在 <code>&lt;/body&gt;</code> 前)</label>
                <textarea class="form-control" id="embed-code-script" rows="6" readonly></textarea>
                <button class="btn btn-primary btn-sm mt-2" id="copy-embed-code">複製全部程式碼</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="interpretationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="interpretation-title">數據解讀</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="interpretation-body">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="detailedReportModal" tabindex="-1" aria-labelledby="detailedReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen"> <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailedReportModalLabel">詳細分析報告書</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="report-iframe" src="about:blank" style="width: 100%; height: calc(100vh - 58px); border: none;"></iframe>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="comparisonModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>比較分析儀表板</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="comparison-content-container">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="esgReportModal" tabindex="-1" aria-labelledby="esgReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="esgReportModalLabel"><i class="fas fa-file-signature me-2"></i>生成 ESG 框架報告摘要</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">此功能將根據目前的分析結果，自動生成符合所選主流 ESG 報告框架的數據摘要，可直接複製用於您的永續報告中。</p>
                <div class="mb-3">
                    <label for="frameworkSelector" class="form-label fw-bold">1. 選擇報告框架</label>
                    <select class="form-select" id="frameworkSelector">
                        <option value="gri">GRI 準則 (通用標準)</option>
                        <option value="sasb_electronics">SASB - 硬體 (Hardware Industry)</option>
                        <option value="eu_taxonomy_plastics">歐盟分類法 - 塑膠製造 (Plastics TSC)</option>
                    </select>
                </div>
                <div class="d-grid">
                    <button type="button" class="btn btn-primary" id="generate-esg-report-btn">
                        <span class="spinner-border spinner-border-sm d-none me-2" role="status" aria-hidden="true"></span>
                        生成報告摘要
                    </button>
                </div>
                <hr>
                <div id="esg-report-output-container" class="mt-3" style="display: none;">
                    <h6>報告摘要預覽：</h6>
                    <textarea class="form-control" id="esg-report-output" rows="12" readonly></textarea>
                    <button class="btn btn-success btn-sm mt-2" id="copy-esg-report-btn"><i class="fas fa-copy me-2"></i>複製到剪貼簿</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="aiOptimizerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-magic text-primary me-2"></i>AI 產品最佳化引擎</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label d-flex align-items-center">1. 你的主要目標是什麼？
                        <i class="fas fa-question-circle text-muted ms-2" style="cursor: pointer;"
                           data-bs-toggle="popover"
                           data-bs-trigger="hover"
                           data-bs-html="true"
                           title="設定 AI 優化目標"
                           data-bs-content="這是您賦予 AI 的核心指令。AI 將根據此目標，在所有可能性中，為您推薦最符合您當前商業策略的方案：<ul class='list-unstyled mb-0 mt-2 text-start'><li><strong class='text-primary'>最大化降低碳足跡</strong>：將優先尋找能達成最大減碳效益的方案，適合以達成氣候目標為首要任務的策略。</li><li><strong class='text-primary'>最大化降低成本</strong>：將優先尋找能最大化節省成本的方案，適合以財務績效為主要考量的策略。</li><li><strong class='text-primary'>均衡優化</strong>：在減碳效益與成本節省之間尋求最佳平衡點，找出『雙贏』或投資報酬率最高的方案。</li><li><strong class='text-primary'>最大化循環性</strong>：將優先推薦能顯著提升產品『再生材料總佔比』的方案，適合以實踐循環經濟為核心的策略。</li></ul>"></i>
                    </label>
                    <select class="form-select" id="mainGoalSelector">
                        <option value="minimize_co2">最大化降低碳足跡 (E)</option>
                        <option value="minimize_s_risk">最大化降低社會風險 (S)</option>
                        <option value="minimize_g_risk">最大化降低治理風險 (G)</option>
                        <option value="minimize_cost">最大化降低成本 (F)</option>
                        <option value="balanced">均衡優化 (兼顧碳排與成本)</option>
                        <option value="maximize_circularity">最大化循環性 (C)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="maxCo2Input" class="form-label d-flex align-items-center">2. 約束條件：
                        <i class="fas fa-question-circle text-muted ms-2" style="cursor: pointer;"
                           data-bs-toggle="popover" data-bs-trigger="hover" title="設定優化底線"
                           data-bs-content="這裡是您為 AI 設定的『底線』或『天花板』，AI 將不會推薦任何超出這些限制的方案。這是一個強大的工具，用於確保優化過程不會產生非預期的負面後果。例如：您可以要求 AI 在『成本不得增加』的前提下，盡可能地降低碳足跡。"></i>
                    </label>
                    <div class="input-group mb-2">
                        <span class="input-group-text">總碳排不高於 (kg CO₂e)</span>
                        <input type="number" class="form-control" id="maxCo2Input">
                    </div>
                    <div class="input-group">
                        <span class="input-group-text">總成本不高於 ($)</span>
                        <input type="number" class="form-control" id="maxCostInput">
                    </div>
                </div>
                <div class="d-grid">
                    <button type="button" class="btn btn-primary" id="runAiOptimizerBtn">
                        <span class="spinner-border spinner-border-sm d-none me-2" role="status"></span>
                        開始運算
                    </button>
                </div>
                <hr>
                <h6>AI 推薦方案：</h6>
                <div id="ai-recommendations-list">
                    <p class="text-muted">點擊開始運算後，結果將顯示於此。</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="scenarioAnalysisModal" tabindex="-1" aria-labelledby="scenarioAnalysisModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="scenarioAnalysisModalLabel"><i class="fas fa-chart-line me-2"></i>敏感度與財務風險模擬</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-lg-5">
                        <ul class="nav nav-tabs" id="scenarioTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active d-flex justify-content-between align-items-center" id="sensitivity-tab" data-bs-toggle="tab" data-bs-target="#sensitivity-pane" type="button" role="tab">
                                    敏感度分析
                                    <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" title="功能解說" data-bs-content="此功能幫助您了解產品的『體質』。透過模擬單一變數的波動，您可以看出最終結果對該變數的敏感程度，從而識別出關鍵的風險因子與優化機會點。"></i>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link d-flex justify-content-between align-items-center" id="financial-tab" data-bs-toggle="tab" data-bs-target="#financial-pane" type="button" role="tab">
                                    財務風險模擬
                                    <i class="fas fa-question-circle text-muted ms-2" data-bs-toggle="popover" data-bs-trigger="hover" title="功能解說" data-bs-content="此功能將 ESG 風險與財務成本直接掛鉤，模擬真實世界的貿易政策（關稅）與市場波動（匯率）對您產品總成本的具體衝擊。"></i>
                                </button>
                            </li>
                        </ul>
                        <div class="tab-content pt-3">
                            <div class="tab-pane fade show active" id="sensitivity-pane" role="tabpanel">
                                <h6>設定敏感度情境</h6>
                                <div class="mb-3">
                                    <label class="form-label small d-flex align-items-center">1. 選擇模擬類型
                                        <i class="fas fa-question-circle text-muted ms-auto" style="cursor: pointer;" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-html="true" title="壓力測試變數" data-bs-content="<ul class='list-unstyled mb-0 small'><li><b>成本敏感度:</b> 模擬原料價格漲跌時，對總成本的影響。</li><li><b>循環度敏感度:</b> 模擬提高再生料比例時，對總碳排與總成本的雙重影響。</li><li><b>供應鏈風險:</b> 模擬特定國家風險升降時，對總體ESG分數的影響。</li><li><b>碳稅衝擊:</b> 模擬未來若開徵碳稅，對產品總成本的財務衝擊。</li></ul>"></i>
                                    </label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="scenario_type" id="scenario_cost" value="cost" checked>
                                        <label class="btn btn-outline-secondary" for="scenario_cost"><i class="fas fa-dollar-sign fa-fw me-2"></i>成本敏感度</label>
                                        <input type="radio" class="btn-check" name="scenario_type" id="scenario_circularity" value="circularity">
                                        <label class="btn btn-outline-secondary" for="scenario_circularity"><i class="fas fa-recycle fa-fw me-2"></i>循環度敏感度</label>
                                        <input type="radio" class="btn-check" name="scenario_type" id="scenario_risk" value="risk">
                                        <label class="btn btn-outline-secondary" for="scenario_risk"><i class="fas fa-shield-alt fa-fw me-2"></i>供應鏈風險</label>
                                        <input type="radio" class="btn-check" name="scenario_type" id="scenario_carbon_tax" value="carbon_tax">
                                        <label class="btn btn-outline-secondary" for="scenario_carbon_tax"><i class="fas fa-smog fa-fw me-2"></i>碳稅衝擊</label>
                                    </div>
                                </div>
                                <div id="scenario-params-container">
                                </div>
                            </div>
                            <div class="tab-pane fade" id="financial-pane" role="tabpanel">
                                <h6>設定匯率與關稅</h6>
                                <div class="mb-3">
                                    <label class="form-label d-flex align-items-center">1. 報告基準貨幣
                                        <i class="fas fa-question-circle text-muted ms-auto" style="cursor: pointer;" data-bs-toggle="popover" data-bs-trigger="hover" title="結算標準" data-bs-content="選擇您希望最終所有成本都換算成哪一種貨幣來呈現。這是所有財務計算的最終結算標準。"></i>
                                    </label>
                                    <select class="form-select" id="baseCurrency"><option value="TWD" selected>新台幣 (TWD)</option><option value="USD">美元 (USD)</option><option value="EUR">歐元 (EUR)</option><option value="CNY">人民幣 (CNY)</option></select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label d-flex align-items-center">2. 模擬匯率
                                        <i class="fas fa-question-circle text-muted ms-auto" style="cursor: pointer;" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-html="true" title="如何輸入匯率" data-bs-content="<ul class='list-unstyled mb-0 small'><li>輸入「1單位外幣可兌換多少基準貨幣」。</li><li class='mt-1'><b>範例：</b>若基準貨幣為 TWD，想模擬 1 美元兌 32.5 新台幣的匯率，請在 USD 欄位輸入 <strong>32.5</strong>。</li></ul>"></i>
                                    </label>
                                    <div class="input-group input-group-sm mb-1"><span class="input-group-text" style="width:80px">USD</span><input type="number" class="form-control exchange-rate-input" data-currency="USD" placeholder="例如：TWD 基準下填 32.5"></div>
                                    <div class="input-group input-group-sm mb-1"><span class="input-group-text" style="width:80px">EUR</span><input type="number" class="form-control exchange-rate-input" data-currency="EUR" placeholder="例如：TWD 基準下填 35.0"></div>
                                    <div class="input-group input-group-sm mb-1"><span class="input-group-text" style="width:80px">CNY</span><input type="number" class="form-control exchange-rate-input" data-currency="CNY" placeholder="例如：TWD 基準下填 4.5"></div>
                                    <div class="input-group input-group-sm"><span class="input-group-text" style="width:80px">JPY</span><input type="number" class="form-control exchange-rate-input" data-currency="JPY" placeholder="例如：TWD 基準下填 0.21"></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label d-flex align-items-center">3. 模擬關稅規則
                                        <i class="fas fa-question-circle text-muted ms-auto" style="cursor: pointer;" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-html="true" title="如何設定關稅" data-bs-content="<ul class='list-unstyled mb-0 small'><li>模擬對特定來源國的物料課徵額外關稅。</li><li class='mt-1'><b>如何使用：</b></li><li class='ps-2'>1. 點擊「新增關稅規則」。</li><li class='ps-2'>2. 選擇要課稅的「來源國」。</li><li class='ps-2'>3. 輸入「關稅稅率(%)」，例如填 25 即代表 25%。</li></ul>"></i>
                                    </label>
                                    <div id="tariff-rules-container"></div>
                                    <button class="btn btn-sm btn-outline-secondary" id="add-tariff-rule-btn"><i class="fas fa-plus"></i> 新增關稅規則</button>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="d-grid">
                            <button type="button" class="btn btn-primary" id="runScenarioAnalysisBtn"><span class="spinner-border spinner-border-sm d-none me-2"></span>開始模擬</button>
                        </div>
                    </div>
                    <div class="col-lg-7 border-start">
                        <h6>模擬結果</h6>
                        <div id="scenario-chart-container" style="height: 250px;"><p class="text-muted text-center pt-5">點擊「開始模擬」後，結果將會顯示於此處。</p></div>
                        <hr>
                        <h6>結果解讀</h6>
                        <div id="scenario-interpretation-container" class="mt-2"><p class="text-muted small">模擬分析的智慧解讀將顯示於此。</p></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="tariff-rule-template">
    <div class="input-group input-group-sm mb-2 tariff-rule">
        <select class="form-select tariff-country-select" style="max-width: 180px;"></select>
        <span class="input-group-text">關稅</span>
        <input type="number" class="form-control tariff-percentage-input" placeholder="例如: 15" min="0">
        <span class="input-group-text">%</span>
        <button class="btn btn-outline-danger remove-tariff-rule-btn" type="button"><i class="fas fa-trash"></i></button>
    </div>
</template>

<div class="modal fade" id="template-chooser-modal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">選擇報告樣板</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>請為您的視覺化報告選擇一個樣板。</p>
                <select class="form-select" id="carousel-template-selector">
                    <option>載入中...</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="generate-carousel-report-btn">產生報告</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="editSourceModal" tabindex="-1" aria-labelledby="editSourceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editSourceModalLabel">編輯詳細來源</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-source-key">
                <div class="mb-3">
                    <label for="edit-source-primary" class="form-label">主要來源 (Primary Source)</label>
                    <input type="text" class="form-control" id="edit-source-primary" placeholder="例如：Ecoinvent 3.9">
                </div>
                <div class="mb-3">
                    <label for="edit-source-secondary" class="form-label">次要來源 (Secondary Source)</label>
                    <input type="text" class="form-control" id="edit-source-secondary" placeholder="例如：環保署-CFP (TW)">
                </div>
                <div class="mb-3">
                    <label for="edit-source-cost" class="form-label">成本資訊來源 (Cost Source)</label>
                    <input type="text" class="form-control" id="edit-source-cost" placeholder="例如：產業平均 (TW, 2025 Q2)">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="save-source-btn">儲存變更</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="qrCodeModalLabel"><i class="fas fa-qrcode me-2"></i>分享報告 QR Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="qrcode-display-container" class="text-center mb-3 d-flex justify-content-center"></div>
                <div class="p-3 bg-light-subtle rounded-3">
                    <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>分享說明</h6>
                    <ul class="small text-muted mb-0 ps-3">
                        <li>使用手機相機掃描 QR Code，即可在行動裝置上開啟此報告。</li>
                        <li>您也可以在 QR Code 圖片上按右鍵，選擇「另存圖片」或「複製圖片」來分享。</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="presetBrowserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">選擇產品樣板</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><input type="search" class="form-control" id="preset-browser-search" placeholder="搜尋樣板名稱..."></div>
                <div id="preset-browser-list"></div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="reportChooserModal" tabindex="-1" aria-labelledby="reportChooserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reportChooserModalLabel"><i class="fas fa-film me-2"></i> 選擇報告以產生比較輪播</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">請勾選至少兩份您想要在輪播簡報中進行比較的歷史報告。</p>
                <div id="report-chooser-list-container">
                </div>
            </div>
            <div class="modal-footer">
                <span id="selection-counter" class="me-auto text-muted">已選擇 0 份報告</span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="generate-comparison-carousel-btn" disabled>
                    <i class="fas fa-play-circle me-2"></i> 產生輪播
                </button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="aiNarrativeModal" tabindex="-1" aria-labelledby="aiNarrativeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="aiNarrativeModalLabel"><i class="fas fa-robot text-primary me-2"></i>AI 永續分析師報告</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small p-2 d-flex align-items-center">
                    <i class="fas fa-info-circle me-2"></i>
                    <div>提醒：每次生成都會呼叫 AI 模型並產生費用，請謹慎使用。</div>
                </div>
                <div class="mb-3">
                    <label for="ai-persona-selector" class="form-label fw-bold">1. 請選擇 AI 的分析視角與語氣：</label>
                    <select class="form-select" id="ai-persona-selector">
                        <option value="consultant">犀利顧問 (批判性、找風險)</option>
                        <option value="marketer">行銷總監 (找亮點、說故事)</option>
                        <option value="analyst">資深分析師 (中立客觀、報數據)</option>
                        <option value="storyteller">故事創作大師 (情感化敘事、引人入勝)</option>
                        <option value="engineer">研發工程師 (技術細節、效能優化)</option>
                        <option value="educator">永續教育講師 (生活化解說、啟發行動)</option>
                        <option value="journalist">調查記者 (揭露真相、新聞視角)</option>
                        <option value="ai_innovator">AI 創新顧問 (科技導入、加速永續)</option>
                        <option value="crisis_pr">危機公關專家 (穩定信心、正面應對)</option>
                    </select>
                </div>

                <div class="d-grid mb-3">
                    <button type="button" class="btn btn-primary" id="generate-narrative-in-modal-btn">
                        <i class="fas fa-magic me-2"></i>產生初始報告
                    </button>
                </div>
                <hr>

                <div id="ai-chat-log" style="min-height: 200px;">
                    <div class="text-center text-muted p-5">
                        <i class="fas fa-lightbulb fa-2x mb-3"></i>
                        <p>請先選擇分析視角，然後點擊上方按鈕產生初始報告。</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <div id="ai-chat-input-container" class="input-group w-100" style="display: none;">
                    <input type="text" id="ai-chat-input" class="form-control" placeholder="針對報告繼續提問...">
                    <button class="btn btn-primary" type="button" id="ai-chat-send-btn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="aiNarrativeModal" tabindex="-1" aria-labelledby="aiNarrativeModalLabel" aria-hidden="true">
</div>

<div class="modal fade" id="dppModal" tabindex="-1" aria-labelledby="dppModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dppModalLabel"><i class="fas fa-passport me-2"></i>數位產品護照 (DPP)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>您已成功為此產品產生數位護照。您可以將下方的 QR Code 或嵌入碼用於您的產品頁面、行銷材料或報告中。</p>
                <div class="row">
                    <div class="col-md-4 text-center">
                        <h6>掃描 QR Code 驗證</h6>
                        <div id="dpp-qrcode-container" class="d-flex justify-content-center p-2 border rounded"></div>
                        <a href="#" id="dpp-verify-link" target="_blank" class="btn btn-sm btn-outline-primary mt-2">手動開啟驗證頁面</a>
                    </div>
                    <div class="col-md-8">
                        <h6>嵌入到您的網站</h6>
                        <p class="small text-muted">複製下方的 iframe 程式碼，貼到您網站的 HTML 中即可顯示此證書小卡。</p>
                        <textarea class="form-control" id="dpp-embed-code" rows="4" readonly></textarea>
                        <button class="btn btn-sm btn-success mt-2" id="dpp-copy-embed-code-btn">
                            <i class="fas fa-copy me-2"></i>複製嵌入碼
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="ai-comms-modal" tabindex="-1" aria-labelledby="aiCommsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="aiCommsModalLabel"><i class="fas fa-robot text-primary me-2"></i>AI 溝通文案產生器</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small p-2 d-flex align-items-center">
                    <i class="fas fa-info-circle me-2"></i>
                    <div>提醒：每次生成都會呼叫 AI 模型並產生費用，請謹慎使用。</div>
                </div>
                <hr>
                <div id="ai-comms-output-container">
                    <div class="text-center p-5">
                        <p class="text-muted">AI 已準備就緒，請點擊按鈕開始生成內容。</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="transportOverridesModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">編輯單項運輸路徑</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="small text-muted">此處的設定將會覆寫所選的「全局運輸路徑」。</p>
                <div id="transport-overrides-list"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button><button type="button" class="btn btn-primary" id="save-transport-overrides-btn">儲存並重新計算</button></div>
        </div>
    </div>
</div>
<div class="modal fade" id="startupModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="startupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="startupModalLabel"><i class="fa-solid fa-leaf text-primary me-2"></i>歡迎使用 ESG 永續材料決策系統</h5>
            </div>
            <div class="modal-body text-center">
                <p class="text-muted">請選擇您要執行的操作：</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary btn-lg" id="start-new-analysis-btn">
                        <i class="fas fa-plus-circle me-2"></i>開始一個全新的分析
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="load-last-session-btn" disabled>
                        <i class="fas fa-history me-2"></i>載入上次的工作階段
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="load-from-history-btn">
                        <i class="fas fa-folder-open me-2"></i>從專案歷程載入
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="startupModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="startupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="startupModalLabel"><i class="fa-solid fa-leaf text-primary me-2"></i>歡迎使用 ESG 永續材料決策系統</h5>
            </div>
            <div class="modal-body text-center">
                <p class="text-muted">請選擇您要執行的操作：</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary btn-lg" id="start-new-analysis-btn">
                        <i class="fas fa-plus-circle me-2"></i>開始一個全新的分析
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="load-last-session-btn" disabled>
                        <i class="fas fa-history me-2"></i>載入上次的工作階段
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="load-from-history-btn">
                        <i class="fas fa-folder-open me-2"></i>從專案歷程載入
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade modal-fullscreen" id="tnfdFullScreenModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">TNFD 自然風險戰情室</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="tnfd-iframe" src="about:blank" style="width: 100%; height: calc(100vh - 58px); border: none;"></iframe>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="extendedAppsModal" tabindex="-1" aria-labelledby="extendedAppsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="extendedAppsModalLabel"><i class="fas fa-puzzle-piece me-2"></i> 延伸應用中心</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" style="height: 60vh;">
                <iframe id="extended-apps-iframe" src="about:blank" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="processBrowserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">選擇製程</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="search" class="form-control" id="process-browser-search" placeholder="搜尋製程名稱或分類...">
                </div>
                <div id="process-browser-list"></div>
            </div>
        </div>
    </div>
</div>

<template id="process-row-template">
    <div class="process-row" data-component-type="process">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-bold"></span>
            <span class="process-description-placeholder ms-2"></span>
            <button type="button" class="btn-close remove-component-btn ms-auto" aria-label="移除"></button>
        </div>
        <div class="mb-2">
            <button type="button" class="btn change-process-btn w-100 d-flex justify-content-between align-items-center">
                <span class="process-name-display">請選擇製程...</span>
                <i class="fas fa-chevron-down fa-xs"></i>
            </button>
        </div>
        <div class="process-options-container mb-2"></div>
        <div class="row g-2">
            <div class="col-12">
                <div class="input-group input-group-sm">
                    <span class="input-group-text">數量</span>
                    <input type="number" class="form-control process-quantity" value="1" min="0" step="any" placeholder="數值">
                    <span class="input-group-text process-unit-display">--</span>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label small mb-1">作用於</label>
                <div class="input-group input-group-sm">
                    <select class="form-select form-select-sm applied-to-selector" placeholder="選擇作用的物料..." multiple=""></select>
                    <button class="btn btn-outline-secondary apply-to-all-btn" type="button" title="全選所有物料">
                        <i class="fas fa-check-double"></i>
                    </button>
                    <button class="btn btn-outline-secondary clear-apply-to-btn" type="button" title="清除所有選項">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
<div class="modal fade" id="aiSuggestionModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-robot text-primary me-2"></i>AI 建議的物料與製程</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <h5 class="alert-heading">請檢視並確認 AI 的辨識結果</h5>
                    <p>AI 已辨識出圖片中的物件為：<strong id="ai-object-name">...</strong></p>
                    <p class="mb-0">系統已自動為您匹配資料庫中的現有項目。對於無法匹配的項目，您可以直接建立新資料。所有操作完成後，點擊最下方的按鈕即可生成BOM表。</p>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <h6>物料建議</h6>
                        <div id="ai-materials-suggestions"></div>
                    </div>
                    <div class="col-md-6">
                        <h6>製程建議</h6>
                        <div id="ai-processes-suggestions"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-success" id="confirm-ai-bom-btn"><i class="fas fa-check me-2"></i>確認並生成BOM</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="ai-component-editor-modal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-robot text-primary me-2"></i>AI 輔助建立新項目</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ai-editor-original-card-id">
                <input type="hidden" id="ai-editor-component-type">

                <form id="ai-material-form" style="display: none;">
                    <h6 class="text-muted">建立新物料</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">物料名稱 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" readonly>
                            <small class="form-text text-muted">此名稱由 AI 辨識，不可修改。</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">物料 KEY <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="key" required placeholder="唯一的英文識別碼，例如 TITANIUM_ALLOY_CASE">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">分類 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="category" required placeholder="例如：金屬、塑膠">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">單位</label>
                            <input type="text" class="form-control" name="unit" value="kg" readonly>
                        </div>
                    </div>
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-2"></i>
                        您只需填寫基礎資訊。點擊儲存後，AI 將自動為您擴充此物料的碳足跡、風險分數等 ESG 數據。
                    </div>
                </form>

                <form id="ai-process-form" style="display: none;">
                    <h6 class="text-muted">建立新製程</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">製程名稱 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" readonly>
                            <small class="form-text text-muted">此名稱由 AI 辨識，不可修改。</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">製程 KEY <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="process_key" required placeholder="唯一的英文識別碼，例如 CNC_MACHINING">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">分類 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="category" required placeholder="例如：金屬加工、表面處理">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">單位</label>
                            <input type="text" class="form-control" name="unit" value="kg" placeholder="AI 將自動建議">
                        </div>
                    </div>
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-2"></i>
                        您只需填寫基礎資訊。點擊儲存後，AI 將自動為您擴充此製程的能耗、數據來源與動態參數等數據。
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="save-ai-component-btn"><i class="fas fa-save me-2"></i>儲存並讓 AI 擴充數據</button>
            </div>
        </div>
    </div>
</div>
<script>
    /*
     ===================================================================
     ===               JavaScript 函式功能列表索引                 ===
     ===================================================================

     --- 1. 初始化與狀態管理 (Initialization & State Management) ---
     1.  $(document).ready(): 頁面載入後所有 JS 功能的進入點，初始化各種事件監聽與設定。
     2.  loadAndApplySettings(): 從後端載入系統設定 (如公司名稱) 並應用到 UI 上。
     3.  loadProjects(): 從後端載入使用者建立的專案列表，並填入下拉選單。
     4.  saveState(): 將當前計算機表單的狀態 (BOM、EOL等) 儲存到瀏覽器的 localStorage。
     5.  loadState(): 從 localStorage 讀取先前儲存的狀態，並還原到頁面上。
     6.  applyTheme(): 應用選擇的顏色主題到整個頁面及圖表。

     --- 2. 核心UI與事件處理 (Core UI & Event Handling) ---
     7.  addMaterialRow(): 在物料清單(BOM)中新增一個空白的物料輸入列。
     8.  updateMaterialRow(): 更新指定的物料列，填入新選擇的物料資訊。
     9.  updateTotalWeight(): 即時計算並顯示所有物料的總重量。
     10. validateEol(): 驗證生命週期終端(EOL)的三個輸入框總和是否為 100%。
     11. triggerCalculation(): 觸發一次完整的後端計算分析。

     --- 3. 儀表板渲染與圖表繪製 (Dashboard Rendering & Charts) ---
     12. updateDashboard(): 儀表板的總控制器，負責建立所有儀表板的 HTML 結構並觸發後續渲染。
     13. initializeDashboardModules(): 由 updateDashboard 呼叫，負責初始化所有圖表和分析模組的繪製。
     14. generateKpiCardHtml(): 產生頂部核心指標(KPI)區塊的 HTML。
     15. populateKpiCards(): 將計算數據填入 KPI 區塊。
     16. generateChartGridHtml(): 產生六個主要分析圖表區塊的 HTML。
     17. generateAnalysisModulesHtml(): 產生所有進階分析模組 (如深度剖析) 的 HTML。
     18. generateEquivalentsCardHtml(): 產生「效益故事化模組」區塊的 HTML。
     19. populateEquivalentsCard(): 將計算數據填入「效益故事化模組」。
     20. generateResultsHeaderHtml(): 產生儀表板頂部的標題與功能按鈕列。
     21. drawChart(): 一個通用的圖表繪製函式。
     22. drawEnvironmentalFingerprintChart(): 繪製環境指紋雷達圖 (七大構面)。
     23. drawRadarChart(): 繪製永續表現四構面雷達圖。
     24. getDoughnutChartOptions(): 提供環圈圖的通用設定。
     25. get...ChartConfig(): 一系列函式，用於產生各個特定圖表(如組成、生命週期)的設定檔。
     26. render...Scorecard() / render...Card(): 一系列函式，用於產生各個計分卡(ESG, 社會, 治理, 生物多樣性等)的 HTML。
     27. renderHolisticAnalysisCard(): 渲染「綜合分析與建議」卡片。

     --- 4. 深度剖析模組 (Deep Dive Modules) ---
     28. showAct() / showCostAct() / showResilienceAct(): 控制深度剖析模組中「三幕劇」的切換。
     29. prepare...Data(): 一系列函式，用於為深度剖析中的圖表準備數據格式。
     30. draw...Charts(): 一系列函式，用於繪製深度剖析模組內的特定圖表。
     31. generate...Narrative...(): 一系列函式，用於產生深度剖析模組中每一幕的 AI 智慧洞察文字。

     --- 5. 特殊功能與彈出視窗 (Modals & Special Features) ---
     32. populateMaterialBrowser(): 填充「選擇物料」彈出視窗中的物料列表。
     33. populatePresetBrowser(): 填充「選擇產品樣板」彈出視窗中的樣板列表。
     34. generateComparativeStaticHTML(): 產生「比較分析儀表板」的完整 HTML。
     35. generateComparisonVerdict(): 產生比較分析的 AI 智慧「比較畫像」與結論。
     36. generateBomDiffView(): 產生比較分析中的 BOM 差異化分析表格。
     37. drawComparativeRadarChart(): 繪製比較分析的雷達圖。
     38. drawBomDeltaChart(): 繪製比較分析中 BOM 變更對碳排影響的圖表。
     39. appendToChatLog(): 在 AI 永續分析師的聊天視窗中加入訊息。
     40. updateScenarioInputs(): 根據選擇的情境類型，動態更新「情境分析」視窗的輸入欄位。
     41. renderScenarioChart(): 渲染敏感度分析的結果圖表。
     42. renderFinancialImpact(): 渲染財務風險模擬的結果與瀑布圖。
     43. generateScenarioInterpretation(): 產生情境分析結果的 AI 智慧解讀。
     44. getFinancialWaterfallChartConfig(): 產生財務衝擊瀑布圖的設定檔。
     45. startLensTour(): 啟動「分析透鏡」的引導式教學功能。
     46. applyStaticHighlighting(): 套用「分析透鏡」的全局高亮效果。

     --- 6. 數據處理與計算 (Data Processing & Calculation) ---
     47. getMaterialByKey(): 從全域物料庫中，依據 key 查找完整的物料資料。
     48. generateHolisticAnalysis(): 在前端計算並產生「綜合分析」所需的數據 (雷達圖分數、永續定位等)。
     49. generateProfileAnalysis(): 核心演算法，根據多項指標產生產品的16種永續定位畫像。
     50. recalculateAndDisplayCommercialBenefits(): 在前端即時重新計算並顯示商業效益儀表板。
     51. generateCommercialCardHTML_JS(): 產生「商業決策儀表板」的 HTML。
     52. getColorForCategory(): 為不同的物料類別分配一個固定的顏色。
     53. createPieIconSvg(): 為供應鏈地圖上的國家標記產生圓餅圖 SVG 圖標。

     --- 7. 輔助工具函式 (Helper Utilities) ---
     54. escapeHtml(): 將字串進行 HTML 編碼，防止 XSS 攻擊。
     55. showLoading(): 顯示或隱藏全螢幕的讀取中遮罩。
     56. displayError(): 在主內容區顯示錯誤訊息。
     57. getInterpretation(): 根據主題，提供彈出視窗中的詳細數據解讀文字。
    */
    $(document).ready(function() {

        <?php
        $is_super_admin_php = (isset($_SESSION['username']) && $_SESSION['username'] === 'superadmin');
        echo "const isSuperAdmin = '" . ($is_super_admin_php ? '1' : '0') . "';";
        ?>

        const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
        popoverTriggerList.forEach(popoverTriggerEl => {
            new bootstrap.Popover(popoverTriggerEl);
        });

        const GRID_FACTORS = <?= $grid_factors_json_content ?>;
        const USE_PHASE_SCENARIOS = <?= $use_phase_scenarios_json_content ?>;
        const ALL_MATERIALS = <?= $materials_json ?>;
        const TRANSPORT_FACTORS = <?= $transport_factors_json ?>;
        const TRANSPORT_ROUTES = <?= $transport_routes_json ?>;
        const CHART_COLORS = <?= json_encode(CHART_COLORS) ?>;
        const COUNTRY_COORDINATES = <?= $country_coords_json ?>;
        const scoreLabels = { 'co2': '氣候變遷', 'acidification': '酸化潛力', 'eutrophication': '優養化潛力', 'ozone_depletion': '臭氧層破壞', 'photochemical_ozone': '光化學煙霧', 'energy': '能源消耗', 'water': '水資源消耗' };



        let PRESET_PRODUCTS = {};
        $.getJSON('preset_products.json', function(data) {
            PRESET_PRODUCTS = data;
        }).fail(function() {
            console.error("錯誤：無法載入 preset_products.json。");
        });

        const presetBrowserModal = new bootstrap.Modal('#presetBrowserModal');

        function populatePresetBrowser(searchTerm = '') {
            searchTerm = searchTerm.trim().toLowerCase();
            const listContainer = $('#preset-browser-list');

            const filteredPresets = Object.keys(PRESET_PRODUCTS).filter(key =>
                PRESET_PRODUCTS[key].name.toLowerCase().includes(searchTerm)
            );

            if (filteredPresets.length === 0) {
                listContainer.html('<p class="text-center text-muted p-3">找不到符合條件的樣板。</p>');
                return;
            }

            let html = '<div class="list-group list-group-flush">';
            filteredPresets.forEach(key => {
                const preset = PRESET_PRODUCTS[key];
                html += `<a href="#" class="list-group-item list-group-item-action" data-key="${escapeHtml(key)}">${escapeHtml(preset.name)}</a>`;
            });
            html += '</div>';
            listContainer.html(html);
        }

        // 當點擊「選擇樣板」按鈕時
        $('#open-preset-browser-btn').on('click', function() {
            populatePresetBrowser(); // 載入完整列表
            presetBrowserModal.show();
        });

        // 監聽 Modal 內的搜尋框
        $('#preset-browser-search').on('input', function() {
            populatePresetBrowser($(this).val());
        });

        // 當在 Modal 中點擊一個樣板時
        $('#preset-browser-list').on('click', '.list-group-item', function(e) {
            e.preventDefault();
            const selectedKey = $(this).data('key');
            if (!selectedKey) return;

            presetBrowserModal.hide();

            if (!confirm(`確定要載入「${escapeHtml(PRESET_PRODUCTS[selectedKey].name)}」嗎？\n這將會覆蓋您目前編輯的物料清單。`)) {
                return;
            }

            const preset = PRESET_PRODUCTS[selectedKey];
            const container = $('#materials-list-container');
            container.empty();

            preset.components.forEach(component => {
                const weightInKg = parseFloat(((preset.total_weight_g / 1000) * (component.weight_pct / 100)).toFixed(3));
                addMaterialRow({
                    materialKey: component.key,
                    weight: weightInKg,
                    percentage: 0,
                    cost: ''
                });
            });

            updateTotalWeight();
            $('#versionName').val(`預設產品：${preset.name.split(' (')[0]}`);
            saveState();

            // 【核心修正】移除 alert() 並觸發自動計算
            triggerCalculation();
        });

        let mapFilterPopover = null;

        // [新增] 載入設定並更新UI (頁尾等)
        function loadAndApplySettings() {
            $.getJSON('?action=get_settings', function(settings) {
                // 更新頁尾
                const companyName = escapeHtml(settings.company_name || '您的公司');
                const companyUrl = escapeHtml(settings.company_url || '#');
                const currentYear = new Date().getFullYear();
                const footerHtml = `ESG 永續材料決策系統 x © ${currentYear} <a href="${companyUrl}" target="_blank" class="text-muted">${companyName}</a>`;
                $('#main-footer').html(footerHtml);

                // 如果是管理員，也更新 Modal
                if (isSuperAdmin === '1') {
                    $('#allowRegistrationSwitch').prop('checked', settings.allow_registration === '1');
                    $('#companyNameInput').val(settings.company_name || '');
                    $('#companyUrlInput').val(settings.company_url || '');
                }
            });
        }

        // 【V3.2 - 完整版】超級管理員 (Superadmin) 的設定視窗互動邏輯
        if (isSuperAdmin === '1') {

            // 當「系統管理員設定」視窗即將顯示時，觸發此事件
            $('#adminSettingsModal').on('show.bs.modal', function(){
                // 遵循註解提示：僅在此時才向後端請求最新的設定數據

                // 1. 顯示讀取中提示
                $('#dppSecretKeyInput').val('讀取中...');
                $('#companyNameInput').val('讀取中...');
                $('#companyUrlInput').val('讀取中...');

                // 2. 發送 AJAX 請求
                $.getJSON('?action=get_settings', function(settings) {
                    // 3. 成功後，將最新數據填入所有欄位
                    $('#allowRegistrationSwitch').prop('checked', settings.allow_registration === '1');
                    $('#companyNameInput').val(settings.company_name || '');
                    $('#companyUrlInput').val(settings.company_url || '');
                    $('#dppSecretKeyInput').val(settings.dpp_secret_key || '尚未設定或讀取失敗');
                }).fail(function() {
                    // 請求失敗的處理
                    alert('無法載入系統設定，請檢查伺服器連線。');
                    $('#dppSecretKeyInput').val('讀取失敗');
                });
            });

            // 當點擊「產生新密鑰」按鈕
            $('#generateDppKeyBtn').on('click', function() {
                // 使用 SweetAlert2 顯示一個專業的警告視窗
                Swal.fire({
                    title: '警告：即將執行高風險操作',
                    icon: 'warning',
                    // 使用 html 屬性來顯示更豐富的內容
                    html: `
                        <p class="text-start">產生新的密鑰將會使所有<b>先前已產生</b>的報告連結（包含QR Code與嵌入碼）<b>立即失效</b>。</p>
                        <p class="text-start small text-muted">因為舊的簽章將無法再通過新密鑰的驗證。此操作無法復原。</p>
                    `,
                    showCancelButton: true,
                    confirmButtonText: '是，我了解風險並繼續',
                    cancelButtonText: '取消',
                    confirmButtonColor: '#dc3545', // 將確認按鈕設為紅色以示警告
                }).then((result) => {
                    // 只有在使用者點擊紅色確認按鈕後，才執行產生密鑰的動作
                    if (result.isConfirmed) {
                        // 使用瀏覽器內建的強力亂數產生器
                        const array = new Uint32Array(8);
                        window.crypto.getRandomValues(array);
                        let newKey = '';
                        for (let i = 0; i < array.length; i++) {
                            newKey += array[i].toString(16).padStart(8, '0');
                        }
                        $('#dppSecretKeyInput').val('key_' + newKey);

                        // 產生後，再給一個提示
                        Swal.fire(
                            '已產生新密鑰！',
                            '請記得點擊右下角的「儲存設定」按鈕以正式啟用新密鑰。',
                            'success'
                        )
                    }
                });
            });

            // 當點擊「儲存設定」按鈕
            $('#saveAdminSettingsBtn').on('click', function() {
                const btn = $(this);
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> 儲存中...');

                // 收集視窗中所有欄位的數據
                const settings = {
                    allow_registration: $('#allowRegistrationSwitch').is(':checked') ? '1' : '0',
                    company_name: $('#companyNameInput').val(),
                    company_url: $('#companyUrlInput').val(),
                    dpp_secret_key: $('#dppSecretKeyInput').val()
                };

                // 發送 AJAX 請求到後端更新
                $.ajax({
                    url: '?action=update_settings',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(settings),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#adminSettingsModal').modal('hide');
                            loadAndApplySettings(); // 儲存成功後，立即更新頁尾等其他UI元素
                        } else {
                            alert('更新失敗: ' + (response.message || '未知錯誤'));
                        }
                    },
                    error: function() {
                        alert('伺服器通訊錯誤，更新失敗。');
                    },
                    complete: function() {
                        btn.prop('disabled', false).text('儲存設定');
                    }
                });
            });
        }


        $('#sidebar-toggle-btn').on('click', function() {
            // 我們只做一件事：切換 body 標籤上的 class
            $('body').toggleClass('sidebar-collapsed');
        });

        loadProjects();

        // 【核心新增】新的選擇器變數
        const orgSelector = $('#project-organization-selector');

        // 【核心新增】函式：載入使用者擁有的組織列表
        function loadOrganizationsForProjectSetup() {
            orgSelector.prop('disabled', true).html('<option>載入中...</option>');
            // 我們可以重複使用 corporate.php 已有的 API 來獲取組織列表
            $.getJSON('corporate.php?action=get_organizations_and_periods', function(data) {
                if (data.success) {
                    orgSelector.empty().html('<option value="">-- 請選擇一個組織 --</option>');
                    data.organizations.forEach(org => {
                        orgSelector.append(`<option value="${org.id}">${escapeHtml(org.name)}</option>`);
                    });
                    // 在列表最後，新增一個管理舊專案的特殊選項
                    orgSelector.append('<hr class="dropdown-divider">');
                    orgSelector.append('<option value="unassigned">👉 [管理未歸屬的舊專案]</option>');
                }
            }).fail(function() {
                orgSelector.html('<option value="">載入組織失敗</option>');
            }).always(() => orgSelector.prop('disabled', false));
        }

        // 【核心新增】事件：當使用者選擇一個組織時
        orgSelector.on('change', function() {
            const orgId = $(this).val();
            const projectSelector = $('#projectSelector');
            projectSelector.prop('disabled', true).html('<option>載入中...</option>');
            $('#newProjectName').hide(); // 選擇新組織時，先隱藏新專案輸入框

            if (!orgId) {
                projectSelector.html('<option value="">-- 請先選擇組織 --</option>');
                return;
            }

            let apiUrl = '';
            if (orgId === 'unassigned') {
                apiUrl = `?action=get_projects`;
                projectSelector.empty();
            } else {
                apiUrl = `?action=get_projects&organization_id=${orgId}`;
                projectSelector.empty().html('<option value="new">-- 建立一個新專案 --</option>');
            }

            $.getJSON(apiUrl, function(projects) {
                if (projects.length === 0) {
                    if (orgId === 'unassigned') {
                        projectSelector.html('<option value="">太好了！沒有未歸屬的舊專案</option>');
                    } else {
                        // 維持原樣，讓使用者可以建立新專案
                    }
                } else {
                    projects.forEach(p => {
                        // 【統一邏輯】與 loadProjects() 函式一樣，根據 p.organization_id 來判斷是否顯示驚嘆號
                        const isOldProject = !p.organization_id;
                        const prefix = isOldProject ? '⚠️ ' : '';
                        const option = $(`<option value="${p.id}">${prefix}${escapeHtml(p.name)}</option>`);
                        if(isOldProject) {
                            option.data('is-old', true);
                        }
                        projectSelector.append(option);
                    });
                }
            }).always(() => {
                projectSelector.prop('disabled', false);
                projectSelector.trigger('change'); // 觸發一次 change 以更新 UI
            });
        });

        // 【核心新增】事件：「建立新組織」按鈕
        $(document).on('click', '#create-new-org-btn', function() {
            Swal.fire({
                title: '建立新組織',
                input: 'text',
                inputPlaceholder: '請輸入新組織的名稱...',
                showCancelButton: true,
                confirmButtonText: '建立',
                cancelButtonText: '取消',
                inputValidator: (value) => {
                    if (!value || value.trim() === '') {
                        return '組織名稱不可為空！'
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const newOrgName = result.value.trim();
                    $.ajax({
                        url: '?action=create_organization',
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ name: newOrgName }),
                        success: function(res) {
                            if (res.success) {
                                // 成功後，直接將新組織加入下拉選單並選中它
                                orgSelector.append(new Option(res.name, res.id, true, true)).trigger('change');
                            } else {
                                Swal.fire('錯誤', res.message, 'error');
                            }
                        }
                    });
                }
            });
        });

        // 頁面載入時就執行一次，以填充組織列表
        loadOrganizationsForProjectSetup();

        // 【V12.3 修正版】函式：載入專案列表 (邏輯不變，確保您的是此版本)
        function loadProjects() {
            const selector = $('#projectSelector');
            selector.prop('disabled', true);
            $.getJSON('?action=get_projects', function(projects) {
                selector.find('option:gt(0)').remove();
                if (projects) {
                    projects.forEach(p => {
                        const isOldProject = !p.organization_id;
                        const prefix = isOldProject ? '⚠️ ' : '';
                        const option = $(`<option value="${p.id}">${prefix}${escapeHtml(p.name)}</option>`);
                        if(isOldProject) {
                            option.data('is-old', true);
                        }
                        selector.append(option);
                    });
                }
            }).always(function() {
                selector.prop('disabled', false);
            });
        }

        // 【核心新增】監聽專案下拉選單的變化，以顯示遷移提示
        $('#projectSelector').on('change', function() {
            const selectedOption = $(this).find('option:selected');
            const isOld = selectedOption.data('is-old');

            // 如果選中的是舊專案，顯示遷移提示並禁用分析按鈕
            if (isOld) {
                $('#migration-prompt').slideDown();
                $('#calculate-btn').prop('disabled', true);
            } else {
                $('#migration-prompt').slideUp();
                $('#calculate-btn').prop('disabled', false);
            }

            // 處理 "建立新專案" 選項的 UI (這部分邏輯不變)
            if ($(this).val() === 'new') {
                $('#newProjectName').show();
            } else {
                $('#newProjectName').hide();
            }
        });

        // 【核心新增】「立即指派組織」按鈕的點擊事件
        $(document).on('click', '#assign-org-btn', function() {
            const projectId = $('#projectSelector').val();
            const projectName = $('#projectSelector').find('option:selected').text().replace('⚠️ ','');

            // 動態從 corporate.php 獲取組織列表來顯示
            $.getJSON('corporate.php?action=get_organizations_and_periods', function(data) {
                if (data.success && data.organizations.length > 0) {
                    const orgOptions = data.organizations.reduce((acc, org) => {
                        acc[org.id] = org.name;
                        return acc;
                    }, {});

                    Swal.fire({
                        title: `指派專案：${projectName}`,
                        html: `請選擇要將此專案移入哪個組織底下：`,
                        input: 'select',
                        inputOptions: orgOptions,
                        inputPlaceholder: '請選擇組織',
                        showCancelButton: true,
                        confirmButtonText: '確認指派',
                        cancelButtonText: '取消',
                        preConfirm: (orgId) => {
                            if (!orgId) {
                                Swal.showValidationMessage('您必須選擇一個組織');
                            }
                            return orgId;
                        }
                    }).then((result) => {
                        if (result.isConfirmed && result.value) {
                            const targetOrgId = result.value;
                            $.ajax({
                                url: '?action=assign_project_organization',
                                type: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify({ project_id: projectId, organization_id: targetOrgId }),
                                success: function(res) {
                                    if (res.success) {
                                        Swal.fire('成功！', '專案已成功歸屬。', 'success');
                                        // 重新載入專案列表，刷新介面
                                        loadProjects();
                                        $('#migration-prompt').hide();
                                        $('#calculate-btn').prop('disabled', false);
                                    } else {
                                        Swal.fire('錯誤', res.message, 'error');
                                    }
                                }
                            });
                        }
                    });
                } else {
                    Swal.fire('錯誤', '找不到任何可用的組織。請先至「企業碳盤查」模組建立組織。', 'error');
                }
            });
        });

// 事件綁定：當選擇不同專案時，顯示/隱藏「新專案名稱」輸入框
        $('#projectSelector').on('change', function() {
            if ($(this).val() === 'new') {
                $('#newProjectName').show();
            } else {
                $('#newProjectName').hide();
            }
        }).trigger('change');

// 事件綁定：重新整理按鈕
        $('#reloadProjectsBtn').on('click', loadProjects);

        let charts = {};
        let perUnitData = null;
        const ALL_PROCESSES = <?= $processes_json ?>;
        const materialBrowserModal = new bootstrap.Modal('#materialBrowserModal');
        const processBrowserModal = new bootstrap.Modal('#processBrowserModal');
        const interpretationModal = new bootstrap.Modal('#interpretationModal');

        // --- UI/UX 相關事件綁定 ---
        $(document).on('input', '#productionQuantity, #sellingPriceInput, #manufacturingCostInput, #sgaCostInput', function() {
            recalculateAndDisplayCommercialBenefits();
        });

        // --- 【V4.0 運輸與使用階段 UI 互動總邏輯】 ---
        const usePhaseSwitch = $('#enableUsePhase');
        const usePhaseInputs = $('.use-phase-input');
        const transportSwitch = $('#enableTransportPhase');
        const transportInputsContainer = $('#transport-phase-inputs-container');
        const transportOverridesModal = new bootstrap.Modal('#transportOverridesModal');
        const globalTransportRouteSelector = $('#globalTransportRoute');

        // 初始化運輸路徑下拉選單 (升級版：支援分類)
        const routeGroups = {};
        Object.keys(TRANSPORT_ROUTES).forEach(key => {
            const route = TRANSPORT_ROUTES[key];
            const group = route.group || '未分類';
            if (!routeGroups[group]) {
                routeGroups[group] = [];
            }
            routeGroups[group].push({ key: key, name: route.name });
        });

        globalTransportRouteSelector.empty(); // 清空舊選項
        for (const groupName in routeGroups) {
            const groupOpt = $(`<optgroup label="${escapeHtml(groupName)}"></optgroup>`);
            routeGroups[groupName].forEach(route => {
                groupOpt.append(`<option value="${route.key}">${escapeHtml(route.name)}</option>`);
            });
            globalTransportRouteSelector.append(groupOpt);
        }

        // 初始化運輸路徑下拉選單
        Object.keys(TRANSPORT_ROUTES).forEach(key => {
            globalTransportRouteSelector.append(`<option value="${key}">${escapeHtml(TRANSPORT_ROUTES[key].name)}</option>`);
        });

        function toggleUsePhaseInputs() {
            const isEnabled = usePhaseSwitch.is(':checked');
            usePhaseInputs.prop('disabled', !isEnabled);
            $('#use-phase-inputs-container').css('opacity', isEnabled ? 1 : 0.6);
        }

        function toggleTransportPhase() {
            const isEnabled = transportSwitch.is(':checked');
            if (isEnabled) {
                transportInputsContainer.slideDown();
            } else {
                transportInputsContainer.slideUp();
            }
        }

        // 初始化並綁定事件
        toggleUsePhaseInputs();
        toggleTransportPhase();
        usePhaseSwitch.on('change', function() { toggleUsePhaseInputs(); triggerCalculation(); });
        transportSwitch.on('change', function() { toggleTransportPhase(); triggerCalculation(); });
        globalTransportRouteSelector.on('change', triggerCalculation);

        // 【V2.0 修正版】編輯單項運輸覆寫的 Modal 邏輯 (更穩健的單次迴圈版本)
        $('#edit-transport-overrides-btn').on('click', function() {
            const listContainer = $('#transport-overrides-list');
            listContainer.empty();
            if ($('.material-row').length === 0 || !$('.material-row').first().data('key')) {
                listContainer.html('<p class="text-muted">請先在主畫面新增物料。</p>');
                transportOverridesModal.show();
                return;
            }

            // 1. 預先準備好所有下拉選單的選項 HTML (這部分不變)
            const routeGroups = {};
            Object.keys(TRANSPORT_ROUTES).forEach(key => {
                const route = TRANSPORT_ROUTES[key];
                const group = route.group || '未分類';
                if (!routeGroups[group]) routeGroups[group] = [];
                routeGroups[group].push({ key, name: route.name });
            });

            let modeOptionsHtml = '';
            Object.keys(TRANSPORT_FACTORS).forEach(key => {
                modeOptionsHtml += `<option value="${key}">${escapeHtml(TRANSPORT_FACTORS[key].name)}</option>`;
            });

            // 2.【核心修改】使用單一迴圈，一次性為每個物料產生完整的、已填好值的編輯介面
            let finalHtml = '';
            $('.material-row').each(function(index) {
                const row = $(this);
                const key = row.data('key');
                if (!key) return; // continue

                const material = getMaterialByKey(key);
                const currentRoute = row.data('transport-route'); // 這可能是字串(預設路徑)或物件(自訂路徑)
                const isCustom = typeof currentRoute === 'object' && currentRoute !== null && currentRoute.name === '_custom';

                // 2.1 準備「預設路徑」頁籤的內容
                let presetOptionsPopulatedHtml = '';
                for (const groupName in routeGroups) {
                    presetOptionsPopulatedHtml += `<optgroup label="${escapeHtml(groupName)}">`;
                    routeGroups[groupName].forEach(route => {
                        // 如果不是自訂路徑，且當前路徑與此選項相符，則設為 selected
                        const isSelected = !isCustom && (currentRoute === route.key || (!currentRoute && $('#globalTransportRoute').val() === route.key));
                        presetOptionsPopulatedHtml += `<option value="${route.key}" ${isSelected ? 'selected' : ''}>${escapeHtml(route.name)}</option>`;
                    });
                    presetOptionsPopulatedHtml += `</optgroup>`;
                }

                // 2.2 準備「自訂路徑」頁籤的內容
                let customLegsHtml = '';
                if (isCustom && Array.isArray(currentRoute.legs)) {
                    currentRoute.legs.forEach(leg => {
                        // 為了正確預選，我們需要動態建立 select 的 HTML
                        let legModeOptions = '';
                        Object.keys(TRANSPORT_FACTORS).forEach(modeKey => {
                            legModeOptions += `<option value="${modeKey}" ${leg.mode === modeKey ? 'selected' : ''}>${escapeHtml(TRANSPORT_FACTORS[modeKey].name)}</option>`;
                        });
                        customLegsHtml += `
                            <div class="input-group input-group-sm mb-2 custom-leg-row">
                                <select class="form-select custom-leg-mode">${legModeOptions}</select>
                                <input type="number" class="form-control custom-leg-distance" placeholder="距離 (km)" value="${leg.distance_km || ''}">
                                <button class="btn btn-outline-danger remove-leg-btn" type="button"><i class="fas fa-trash"></i></button>
                            </div>`;
                    });
                }

                // 2.3 組合出完整的卡片 HTML
                finalHtml += `
                <div class="card mb-3" data-material-key="${key}">
                    <div class="card-header"><strong>${escapeHtml(material.name)}</strong></div>
                    <div class="card-body">
                        <ul class="nav nav-tabs nav-fill" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link ${!isCustom ? 'active' : ''}" data-bs-toggle="tab" data-bs-target="#preset-pane-${index}" type="button">選擇預設路徑</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link ${isCustom ? 'active' : ''}" data-bs-toggle="tab" data-bs-target="#custom-pane-${index}" type="button">自訂複合路徑</button>
                            </li>
                        </ul>
                        <div class="tab-content pt-3">
                            <div class="tab-pane fade ${!isCustom ? 'show active' : ''}" id="preset-pane-${index}">
                                <select class="form-select preset-route-selector">${presetOptionsPopulatedHtml}</select>
                            </div>
                            <div class="tab-pane fade ${isCustom ? 'show active' : ''}" id="custom-pane-${index}">
                                <div class="custom-legs-container">${customLegsHtml}</div>
                                <button class="btn btn-sm btn-outline-success mt-2 add-leg-btn"><i class="fas fa-plus"></i> 新增一段運輸</button>
                            </div>
                        </div>
                    </div>
                </div>`;
            });

            // 3. 將最終產生好的 HTML 一次性放入容器
            listContainer.html(finalHtml);
            transportOverridesModal.show();
        });

        // 【全新】動態路徑建構器：新增路段
        $(document).on('click', '.add-leg-btn', function() {
            const container = $(this).prev('.custom-legs-container');
            let modeOptionsHtml = '';
            Object.keys(TRANSPORT_FACTORS).forEach(key => {
                modeOptionsHtml += `<option value="${key}">${escapeHtml(TRANSPORT_FACTORS[key].name)}</option>`;
            });
            const newLegHtml = `
                <div class="input-group input-group-sm mb-2 custom-leg-row">
                    <select class="form-select custom-leg-mode">${modeOptionsHtml}</select>
                    <input type="number" class="form-control custom-leg-distance" placeholder="距離 (km)">
                    <button class="btn btn-outline-danger remove-leg-btn" type="button"><i class="fas fa-trash"></i></button>
                </div>
            `;
            container.append(newLegHtml);
        });

        // 【全新】動態路徑建構器：移除路段
        $(document).on('click', '.remove-leg-btn', function() {
            $(this).closest('.custom-leg-row').remove();
        });

        // 【全新升級版】儲存運輸覆寫設定
        $('#save-transport-overrides-btn').on('click', function() {
            $('#transport-overrides-list .card').each(function() {
                const card = $(this);
                const key = card.data('material-key');
                const materialRow = $(`.material-row[data-key="${key}"]`);
                const activeTab = card.find('.nav-link.active').attr('data-bs-target');

                if (activeTab.includes('custom-pane')) {
                    // 儲存自訂路徑
                    const customRoute = {
                        name: "_custom", // 特殊標記，表示這是一個自訂路徑
                        legs: []
                    };
                    card.find('.custom-leg-row').each(function() {
                        const mode = $(this).find('.custom-leg-mode').val();
                        const distance = parseFloat($(this).find('.custom-leg-distance').val());
                        if (mode && distance > 0) {
                            customRoute.legs.push({ mode: mode, distance_km: distance });
                        }
                    });
                    materialRow.data('transport-route', customRoute).attr('data-transport-route', JSON.stringify(customRoute));
                } else {
                    // 儲存預設路徑
                    const routeKey = card.find('.preset-route-selector').val();
                    materialRow.data('transport-route', routeKey).attr('data-transport-route', routeKey);
                }

                // 清除舊的距離覆寫屬性 (因為新邏輯已包含距離)
                materialRow.removeData('transport-overrides').removeAttr('data-transport-overrides');
            });

            saveState();
            transportOverridesModal.hide();
            triggerCalculation();
        });

        // --- AI 最佳化引擎 v2.0 ---
        let aiOptimizerModal = document.getElementById('aiOptimizerModal') ? new bootstrap.Modal('#aiOptimizerModal') : null;

        // v2.0 新增：當設定視窗打開時，進行前提檢查與智慧設定
        $('#aiOptimizerModal').on('show.bs.modal', function() {
            if (!perUnitData) return;

            // --- 碳排智慧設定與提示 ---
            const baseCo2 = perUnitData.impact.co2;
            const suggestedCo2 = (baseCo2 * 1.05).toFixed(3);
            $('#maxCo2Input').val(suggestedCo2);

            // 移除舊提示，避免重複
            $('#maxCo2Input').closest('.input-group').next('.form-text').remove();
            // 加上新提示
            $('#maxCo2Input').closest('.input-group').after(`<div class="form-text small text-muted">提示：目前產品碳排為 ${baseCo2.toFixed(3)} kg，已為您預設放寬 5% 的搜尋空間。</div>`);

            const allComponentsHaveCost = perUnitData.inputs.components.every(c => {
                const cost = parseFloat(c.cost);
                return !isNaN(cost) && cost > 0;
            });

            // --- 成本智慧設定與提示 ---
            const hasCostData = perUnitData.impact.cost > 0;
            const costInputGroup = $('#maxCostInput').closest('.input-group');

            // 移除舊提示
            costInputGroup.next('.form-text').remove();

            if (hasCostData) {
                const baseCost = perUnitData.impact.cost;
                const suggestedCost = (baseCost * 1.05).toFixed(2);
                $('#maxCostInput').val(suggestedCost).prop('disabled', false);
                $('#mainGoalSelector option[value="minimize_cost"]').prop('disabled', false);
                costInputGroup.find('.input-group-text').removeClass('text-muted');

                if (!allComponentsHaveCost) {
                    costInputGroup.after(`<div class="form-text text-warning small"><i class="fas fa-exclamation-triangle me-1"></i><b>注意：</b>部分組件缺少成本數據，成本相關的優化建議可能不完全準確。</div>`);
                } else {
                    costInputGroup.after(`<div class="form-text small text-muted">提示：目前產品成本為 $${baseCost.toFixed(2)}，已為您預設放寬 5% 的搜尋空間。</div>`);
                }

            } else {
                // 如果無成本數據，則禁用所有成本相關選項
                $('#maxCostInput').val('').prop('disabled', true);
                $('#mainGoalSelector').val('minimize_co2');
                $('#mainGoalSelector option[value="minimize_cost"]').prop('disabled', true);
                costInputGroup.find('.input-group-text').addClass('text-muted');

                // 加上無成本的警告提示
                costInputGroup.after('<div class="form-text text-warning small">提示：您尚未輸入成本，AI 將僅專注於碳排最佳化。</div>');
            }
        });

        $('#runAiOptimizerBtn').on('click', function() {
            if (!perUnitData) { alert('請先進行一次分析'); return; }

            const btn = $(this);
            const originalText = btn.html();
            btn.html('<span class="spinner-border spinner-border-sm"></span> AI 運算中...').prop('disabled', true);
            $('#ai-recommendations-list').html('<p class="text-muted">AI 正在評估數百種可能性，請稍候...</p>');

            const payload = {
                main_goal: $('#mainGoalSelector').val(),
                constraints: {
                    max_co2: $('#maxCo2Input').val(),
                    max_cost: $('#maxCostInput').val()
                },
                original_bom: {
                    components: perUnitData.inputs.components,
                    eol: perUnitData.inputs.eol_scenario
                },
                has_cost_data: perUnitData.impact.cost > 0
            };

            $.ajax({
                url: '?action=find_optimal_bom',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                success: function(response) {
                    if (response.success && response.recommendations.length > 0) {
                        const base = response.base_scores;

                        const categorized = { circular_upgrade: [], quick_win: [], cost_saver: [], high_potential: [], innovative_leap: [], alternative: [] };
                        response.recommendations.forEach(rec => {
                            if (categorized[rec.category]) categorized[rec.category].push(rec);
                            else categorized.alternative.push(rec);
                        });

                        const category_titles = {
                            circular_upgrade: { title: '【循環升級】提升再生比例', icon: 'fa-recycle', color: 'info' },
                            quick_win: { title: '【快速斬獲】低風險方案', icon: 'fa-check-circle', color: 'success' },
                            cost_saver: { title: '【成本節省】高降本潛力方案', icon: 'fa-dollar-sign', color: 'primary' },
                            high_potential: { title: '【高效益】高影響力權衡方案', icon: 'fa-rocket', color: 'warning' },
                            innovative_leap: { title: '【跨類創新】前瞻性材料建議', icon: 'fa-lightbulb', color: 'warning' },
                            alternative: { title: '其他可行方案', icon: 'fa-th-list', color: 'secondary' }
                        };

                        const generateBeforeAfterChart = (label, before, after, lowerIsBetter = true) => {
                            const maxVal = Math.max(Math.abs(before), Math.abs(after), 0.0001);
                            const beforePct = (before / maxVal) * 100;
                            const afterPct = (after / maxVal) * 100;
                            const colorClass = (lowerIsBetter ? (after < before) : (after > before)) ? 'var(--primary)' : 'var(--bs-danger)';
                            const change = after - before;
                            const changePct = before !== 0 ? (change / Math.abs(before)) * 100 : (after > 0 ? 100 : 0);
                            return `
                                <div class="comparison-bar">
                                    <div class="label">${label}</div>
                                    <div class="bars"><div class="bar bar-before" style="width: ${Math.abs(beforePct)}%;"></div><div class="bar bar-after" style="width: ${Math.abs(afterPct)}%; background-color:${colorClass};"></div></div>
                                    <div class="value fw-bold ps-2" style="color:${colorClass};">${change >= 0 ? '↑' : '↓'}${Math.abs(change).toFixed(2)} (${Math.abs(changePct).toFixed(0)}%)</div>
                                </div>`;
                        };

                        let html = '';
                        for (const category in categorized) {
                            if (categorized[category].length > 0) {
                                const cat_info = category_titles[category];
                                html += `<h6 class="mt-4 mb-2 text-${cat_info.color}"><i class="fas ${cat_info.icon} me-2"></i>${cat_info.title}</h6>`;
                                html += '<div class="list-group">';
                                categorized[category].slice(0, 5).forEach(rec => {
                                    const co2DeltaHtml = `<span>Δ 碳排(E): <strong class="${rec.co2_delta > 0 ? 'text-success' : 'text-danger'}">${rec.co2_delta > 0 ? '↓' : '↑'}${Math.abs(rec.co2_delta).toFixed(2)}</strong> kg</span>`;
                                    const costDeltaHtml = payload.has_cost_data ? `<span>Δ 成本(F): <strong class="${rec.cost_delta >= 0 ? 'text-success' : 'text-danger'}">${rec.cost_delta >= 0 ? '↓' : '↑'}${Math.abs(rec.cost_delta).toFixed(2)}</strong> 元</span>` : '';
                                    const sRiskDeltaHtml = `<span>Δ S-Risk: <strong class="${rec.s_risk_delta > 0 ? 'text-success' : 'text-danger'}">${rec.s_risk_delta > 0 ? '↓' : '↑'}${Math.abs(rec.s_risk_delta).toFixed(1)}</strong></span>`;
                                    const gRiskDeltaHtml = `<span>Δ G-Risk: <strong class="${rec.g_risk_delta > 0 ? 'text-success' : 'text-danger'}">${rec.g_risk_delta > 0 ? '↓' : '↑'}${Math.abs(rec.g_risk_delta).toFixed(1)}</strong></span>`;

                                    const after_co2 = base.co2 - rec.co2_delta;
                                    const after_cost = base.cost - rec.cost_delta;
                                    const after_s = base.s_risk - rec.s_risk_delta;
                                    const after_g = base.g_risk - rec.g_risk_delta;

                                    const tooltipContent = `
                                        <div class='text-start'>
                                            <h6 class='small fw-bold text-center mb-2 text-muted'>若採納此建議... (前後對比)</h6>
                                            ${generateBeforeAfterChart('碳排 (E)', base.co2, after_co2, true)}
                                            ${generateBeforeAfterChart('S-Risk', base.s_risk, after_s, true)}
                                            ${generateBeforeAfterChart('G-Risk', base.g_risk, after_g, true)}
                                            ${payload.has_cost_data ? generateBeforeAfterChart('成本 (F)', base.cost, after_cost, true) : ''}
                                        </div>
                                    `;

                                    // 【最終修正】1. 使用 data-bs-title 屬性  2. 移除 escapeHtml()
                                    html += `<a href="#" class="list-group-item list-group-item-action load-ai-recommendation"
                                                data-bom='${escapeHtml(JSON.stringify(rec.bom))}'
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="right"
                                                data-bs-html="true"
                                                data-bs-title='${tooltipContent.replace(/'/g, "&apos;")}'>
                                        <div class="d-flex w-100 justify-content-between">
                                            <div class="mb-1 small">${rec.description}</div>
                                            <small class="text-success fw-bold text-nowrap ps-2">點此載入 <i class="fas fa-chevron-right fa-xs"></i></small>
                                        </div>
                                        <small class="d-flex justify-content-start flex-wrap gap-3 fw-bold">
                                            ${co2DeltaHtml} ${sRiskDeltaHtml} ${gRiskDeltaHtml} ${costDeltaHtml}
                                        </small>
                                    </a>`;
                                });
                                html += '</div>';
                            }
                        }
                        $('#ai-recommendations-list').html(html);

                        const tooltipTriggerList = document.querySelectorAll('#ai-recommendations-list [data-bs-toggle="tooltip"]');
                        [...tooltipTriggerList].forEach(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl, {
                            customClass: 'ai-tooltip',
                            trigger: 'hover'
                        }));

                    } else {
                        const message = response.message || '找不到更佳的優化方案。';
                        $('#ai-recommendations-list').html(`<div class="alert alert-warning mt-3"><i class="fas fa-info-circle me-2"></i>${escapeHtml(message)}</div>`);
                    }
                },
                error: function() {
                    $('#ai-recommendations-list').html('<p class="text-danger">運算時發生伺服器錯誤。</p>');
                },
                complete: function() {
                    btn.html(originalText).prop('disabled', false);
                }
            });
        });

        $(document).on('click', '.load-ai-recommendation', function(e){
            e.preventDefault();
            const recommendedBom = $(this).data('bom');
            const container = $('#materials-list-container');
            container.empty();
            // 注意：AI 回傳的 bom 結構是 charts.composition，它包含了更完整的數據
            // 我們需要將其轉換為 addMaterialRow 所需的格式
            recommendedBom.forEach(c => {
                const component_for_row = {
                    materialKey: c.key,
                    weight: c.weight,
                    percentage: c.percentage,
                    // 如果有成本數據，反算出單位成本; 否則留空
                    cost: (perUnitData.impact.cost > 0 && c.weight > 0) ? (c.cost / c.weight).toFixed(2) : ''
                };
                addMaterialRow(component_for_row);
            });
            updateTotalWeight();
            validateEol();
            $('#calculate-btn').trigger('click');
            if(aiOptimizerModal) {
                aiOptimizerModal.hide();
            }
        });

        // --- Theme Engine ---
        const THEMES = {
            'light-green': { chartColors: ['#198754', '#20c997', '#36b9cc', '#ffc107', '#fd7e14', '#6c757d'], chartFontColor: '#495057' },
            'dark-green': { chartColors: ['#20c997', '#36b9cc', '#ffc107', '#fd7e14', '#6c757d', '#f8f9fa'], chartFontColor: '#f8f9fa' },
            'morandi-apricot': { chartColors: ['#c7a48b', '#a8836a', '#6f5e53', '#e9e2d9', '#d1bfae', '#8b7a6e'], chartFontColor: '#6f5e53'},
            'morandi-pink': { chartColors: ['#b2888c', '#c3a3a4', '#d4c1c0', '#a47d80', '#906b6e', '#7b5a5e'], chartFontColor: '#5b514f' },
            'morandi-blue': { chartColors: ['#6a8e98', '#88a6af', '#a6c0c8', '#547883', '#43656f', '#355058'], chartFontColor: '#515e61' },
            'morandi-green': { chartColors: ['#869684', '#a0b09e', '#bbcbc8', '#728270', '#5e6d5d', '#4c584b'], chartFontColor: '#596158' },
            'ocean-blue': { chartColors: ['#0077b6', '#4895ef', '#56cfe1', '#00b4d8', '#03045e', '#adb5bd'], chartFontColor: '#033f63' },
            'sunset-orange': { chartColors: ['#f77f00', '#fcbf49', '#d62828', '#eae2b7', '#003049', '#780000'], chartFontColor: '#4f2c00' },

            'royal-purple': { chartColors: ['#8338ec', '#a85cf9', '#be92f6', '#ff006e', '#3a86ff', '#f0e6ff'], chartFontColor: '#f0e6ff' },
            'earth-tones': { chartColors: ['#8f7d5b', '#a8997b', '#c1b59a', '#706040', '#5c4e30', '#eaddcf'], chartFontColor: '#5c4e30' },
            'grayscale': { chartColors: ['#555', '#777', '#999', '#bbb', '#ddd', '#333'], chartFontColor: '#333' },
            'sakura-dream': { chartColors: ['#ffb7c5', '#de7b90', '#c498a2', '#8c6b73', '#f2d0d9', '#5c474b'], chartFontColor: '#5c474b' },
            'lush-forest': { chartColors: ['#4a7c59', '#93b0a0', '#c7ad8b', '#6b5e49', '#d8d8d0', '#5a6e60'], chartFontColor: '#d8d8d0' },
            'cyberpunk-neon': { chartColors: ['#00f0ff', '#ff00f2', '#7b78d1', '#fcee0a', '#d1d0f0', '#3d3a8a'], chartFontColor: '#d1d0f0' }
        };

        $('#theme-selector, #mobile-theme-selector').on('change', function() {
            const selectedTheme = $(this).val();
            $('#theme-selector').val(selectedTheme);
            $('#mobile-theme-selector').val(selectedTheme);
            applyTheme(selectedTheme);
        });

        function applyTheme(themeName) {
            $('html').attr('data-theme', themeName);
            const themeConfig = THEMES[themeName];
            if (themeConfig) {
                Chart.defaults.color = themeConfig.chartFontColor;
                Chart.defaults.borderColor = themeConfig.chartFontColor + '20';
            }
            if (perUnitData) {
                updateDashboard();
            }
            localStorage.setItem('selectedTheme', themeName);
        }

        function escapeHtml(text) { if(typeof text !== 'string') return ''; return text.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]); }

        function getMaterialByKey(key) { return ALL_MATERIALS.find(m => m.key === key); }

        function updateTotalWeight() { let total = 0; $('.material-weight').each(function() { total += parseFloat($(this).val()) || 0; }); $('#total-weight-display').text(total.toFixed(3) + ' kg'); }

        function validateEol() {
            let total = 0;
            $('.eol-input').each(function() { total += parseFloat($(this).val()) || 0; });
            $('#eol-warning').toggleClass('d-none', Math.abs(total - 100) < 0.01);
        }

        /**
         * 【V3.0 運輸路徑版 - 製程複選升級】將當前表單狀態儲存到 localStorage
         */
        function saveState() {
            const state = {
                projectId: $('#projectSelector').val(),
                newProjectName: $('#newProjectName').val(),
                versionName: $('#versionName').val(),
                quantity: $('#productionQuantity').val(),
                eol: {
                    recycle: $('#eolRecycle').val(),
                    incinerate: $('#eolIncinerate').val(),
                    landfill: $('#eolLandfill').val()
                },
                use_phase_enabled: $('#enableUsePhase').is(':checked'),
                use_phase_values: {
                    lifespan: $('#usePhaseLifespan').val(),
                    kwh: $('#usePhaseKwh').val(),
                    region: $('#usePhaseGrid').val(),
                    water: $('#usePhaseWater').val()
                },
                transport_phase_enabled: $('#enableTransportPhase').is(':checked'),
                transport_global_route: $('#globalTransportRoute').val(),
                components: []
            };

            $('#materials-list-container').children().each(function() {
                const row = $(this);
                const componentType = row.data('component-type');

                if (componentType === 'material') {
                    const transportRouteData = row.data('transport-route');
                    state.components.push({
                        componentType: 'material',
                        materialKey: row.data('key'),
                        weight: row.find('.material-weight').val(),
                        percentage: row.find('.material-percentage').val(),
                        cost: row.find('.material-cost').val(),
                        transportRoute: transportRouteData,
                        transportOverrides: row.data('transport-overrides') ? JSON.parse(row.data('transport-overrides')) : null
                    });
                } else if (componentType === 'process') {
                    const selectedOptions = {};
                    row.find('.process-option-select').each(function() {
                        selectedOptions[$(this).data('option-key')] = $(this).val();
                    });
                    const appliedToSelector = row.find('.applied-to-selector')[0];

                    state.components.push({
                        componentType: 'process',
                        processKey: row.data('key'),
                        quantity: row.find('.process-quantity').val(),
                        selectedOptions: selectedOptions,
                        appliedToComponentKey: appliedToSelector.tomselect ? appliedToSelector.tomselect.getValue() : []
                    });
                }
            });
            localStorage.setItem('ecoCalculatorState', JSON.stringify(state));
        }

        // 1. 監聽歷史報告中的複選框變化
        $('#historyModal').on('change', 'input[type="radio"][name="baseline_select"], input[type="radio"][name="comparison_select"]', function() {
            const modal = $('#historyModal');
            const compareBtn = $('#compare-reports-btn');
            const swapBtn = $('#swap-comparison-btn'); // 取得交换按鈕

            const baselineId = modal.find('input[name="baseline_select"]:checked').val();
            const comparisonId = modal.find('input[name="comparison_select"]:checked').val();

            modal.find('tbody tr').each(function() {
                $(this).removeClass('table-primary table-info');
                $(this).find('input[type="radio"]').prop('disabled', false);
            });

            if (baselineId) {
                const baselineRow = modal.find(`tr[data-view-id="${baselineId}"]`);
                baselineRow.addClass('table-primary');
                baselineRow.find('input[name="comparison_select"]').prop('disabled', true);
            }
            if (comparisonId) {
                const comparisonRow = modal.find(`tr[data-view-id="${comparisonId}"]`);
                comparisonRow.addClass('table-info');
                comparisonRow.find('input[name="baseline_select"]').prop('disabled', true);
            }

            if (baselineId && comparisonId) {
                compareBtn.prop('disabled', false).removeClass('btn-secondary btn-danger').addClass('btn-success');
                compareBtn.html('<i class="fas fa-check-circle me-2"></i>開始比較 (2/2)');
                swapBtn.show(); // 【核心修改】显示交换按鈕
            } else {
                compareBtn.prop('disabled', true).removeClass('btn-success btn-danger').addClass('btn-secondary');
                const count = (baselineId ? 1 : 0) + (comparisonId ? 1 : 0);
                let needed = [];
                if (!baselineId) needed.push('基準(A)');
                if (!comparisonId) needed.push('比較(B)');
                compareBtn.html(`<i class="fas fa-exchange-alt me-2"></i>請選擇 ${needed.join(' 與 ')} (${count}/2)`);
                swapBtn.hide(); // 【核心修改】隐藏交换按鈕
            }
        });

        $('#historyModal').on('click', '#swap-comparison-btn', function() {
            const modal = $('#historyModal');
            const oldBaselineId = modal.find('input[name="baseline_select"]:checked').val();
            const oldComparisonId = modal.find('input[name="comparison_select"]:checked').val();

            if (oldBaselineId && oldComparisonId) {
                // 清除現有的選擇
                modal.find('input[name="baseline_select"]:checked').prop('checked', false);
                modal.find('input[name="comparison_select"]:checked').prop('checked', false);

                // 進行反向選擇
                modal.find(`input[name="baseline_select"][value="${oldComparisonId}"]`).prop('checked', true);
                modal.find(`input[name="comparison_select"][value="${oldBaselineId}"]`).prop('checked', true);

                // 【關鍵】手動觸發一次 change 事件，讓UI（高亮、禁用、按鈕文字）全部自動更新
                modal.find('input[name="baseline_select"]:checked').trigger('change');
            }
        });


// --- [V5.3 最終修正版] 比較分析儀表板 核心函式 ---

        /**
         * [V5.3] 監聽比較按鈕的點擊事件 (修正了函式呼叫)
         */
        $('#historyModal').on('click', '#compare-reports-btn', function() {
            if ($(this).is(':disabled')) return;

            const baselineId = $('#historyModal input[name="baseline_select"]:checked').val();
            const comparisonId = $('#historyModal input[name="comparison_select"]:checked').val();
            const ids = [baselineId, comparisonId];

            const comparisonModal = new bootstrap.Modal('#comparisonModal');
            comparisonModal.show();
            $('#comparison-content-container').html('<div class="text-center p-5"><div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div><p class="mt-3 text-muted">正在載入比較戰情室...</p></div>');

            $.getJSON(`?action=get_comparison_data&ids[]=${ids[0]}&ids[]=${ids[1]}`)
                .done(function(response) {
                    if (response.success) {
                        $('#comparisonModal').data('comparisonData', response.data);
                        const staticHtml = generateComparativeStaticHTML(response.data);
                        $('#comparison-content-container').html(staticHtml);
                    } else {
                        $('#comparison-content-container').html(`<div class="alert alert-danger">${'載入比較數據失敗：' + response.message}</div>`);
                    }
                })
                .fail(function() {
                    $('#comparison-content-container').html(`<div class="alert alert-danger">與伺服器通訊失敗，無法取得比較數據。</div>`);
                });
        });

        /**
         * [V5.3 - BUG修復] 監聽比較視窗「已完全顯示」的事件
         * @description 修正了BOM Delta圖表無法讀取碳排數據的錯誤
         */
        $('#comparisonModal').on('shown.bs.modal', function () {
            const data = $(this).data('comparisonData');
            if (data) {
                drawComparativeRadarChart(data);

                const [d1, d2] = data;

                // 【錯誤修復】改用 'charts.composition'，這裡才包含完整的物料細節 (co2, name)
                const bom1_details = d1.charts.composition.reduce((acc, c) => { acc[c.key] = c; return acc; }, {});
                const bom2_details = d2.charts.composition.reduce((acc, c) => { acc[c.key] = c; return acc; }, {});

                const allKeys = [...new Set([...Object.keys(bom1_details), ...Object.keys(bom2_details)])];

                const bomChanges = allKeys.map(key => {
                    const c1 = bom1_details[key]; // 來自報告 A 的物料數據
                    const c2 = bom2_details[key]; // 來自報告 B 的物料數據

                    // 計算碳排影響的變化 (報告A - 報告B)。正值代表改善。
                    const co2_impact = (c1?.co2 || 0) - (c2?.co2 || 0);

                    let type = 'unchanged';

                    // 從原始輸入判斷變更類型
                    const input_c1 = d1.inputs.components.find(c => c.materialKey === key);
                    const input_c2 = d2.inputs.components.find(c => c.materialKey === key);

                    if (input_c1 && input_c2) {
                        if (Math.abs(input_c1.weight - input_c2.weight) > 1e-9 || Math.abs(input_c1.percentage - input_c2.percentage) > 1e-9) {
                            type = 'modified';
                        }
                    } else if (!input_c1 && input_c2) {
                        type = 'added';
                    } else if (input_c1 && !input_c2) {
                        type = 'removed';
                    }

                    return {
                        type,
                        name: c1?.name || c2?.name, // 【錯誤修復】從正確的來源取得物料名稱
                        co2_impact: co2_impact
                    };
                });

                // 呼叫繪圖函式，現在傳入的是正確的數據
                drawBomDeltaChart({ d1, d2, bomChanges });
            }
        });


        /**
         * [V5.8 專家體驗版] 產生全新的「比較畫像」分析模組
         * @param {Array} comparisonResults - 包含指標變化詳情的陣列
         * @returns {string} - 包含畫像、洞察、策略的 HTML 卡片
         */
        function generateComparisonVerdict(comparisonResults) {
            // 從比較結果中提取關鍵數據
            const co2Metric = comparisonResults.find(r => r.key === 'co2');
            const co2_pct_change = co2Metric ? co2Metric.pct : 0;
            const improvements = comparisonResults.filter(r => r.isImprovement === true).length;
            const worsenings = comparisonResults.filter(r => r.isImprovement === false).length;
            const worsenedMetrics = comparisonResults.filter(r => r.isImprovement === false).map(r => `「${r.name}」`).join('、');

            let profile = {
                title: '分析中...',
                icon: 'fa-question-circle',
                style: 'style="background-color: var(--bs-secondary-bg);"',
                insight: '無法產生分析，數據不完整。',
                strategy: '請確保兩份報告的數據都有效。'
            };

            // 根據變化模式，決定「比較畫像」
            if (co2_pct_change > 25 && improvements > worsenings && worsenings <= 1) {
                profile = {
                    title: '重大突破性進化',
                    icon: 'fa-trophy',
                    style: 'style="background-color: rgba(var(--primary-rgb), 0.15); border-left: 5px solid var(--primary);"',
                    insight: `本次迭代取得了卓越的成功。核心指標<b>「總碳足跡」大幅改善了 ${co2_pct_change.toFixed(0)}%</b>，同時在 ${improvements} 項指標上取得進步，實現了全面的、高影響力的永續性躍升。`,
                    strategy: `您的策略重點應是<b>「鞏固與擴散」</b>。深入分析達成此次突破的關鍵設計（例如，某個新材料的應用），並嘗試將此成功經驗標準化，應用到其他產品線，最大化您的領先優勢。`
                };
            } else if (co2_pct_change > 10 && worsenings > 0) {
                profile = {
                    title: '戰略性權衡取捨',
                    icon: 'fa-balance-scale-right',
                    style: 'style="background-color: rgba(var(--bs-warning-rgb), 0.1); border-left: 5px solid var(--bs-warning);"',
                    insight: `本次迭代成功達成了在<b>「總碳足跡」上 ${co2_pct_change.toFixed(0)}% 的顯著改善</b>，但這是在犧牲了 ${worsenings} 項指標（如 ${worsenedMetrics}）的表現下完成的。這是一次目標明確、有所取捨的戰略決策。`,
                    strategy: `「管理權衡關係」是LCA實踐的核心。您的下一步策略應是審視造成這些負面影響的根本原因，評估是否有「雙贏」的解決方案能緩解這些權衡，進一步提升產品的整體永-續韌性。`
                };
            } else if (improvements > worsenings && worsenings === 0) {
                profile = {
                    title: '全面性優化',
                    icon: 'fa-check-double',
                    style: 'style="background-color: rgba(var(--primary-rgb), 0.1); border-left: 5px solid var(--primary);"',
                    insight: `一次完美的迭代。新設計在所有 ${improvements} 項被比較的指標上，均取得了<b>無負面影響的純粹改善</b>。這代表您的永續設計流程非常穩健，能有效避免問題轉移 (Problem Shifting)。`,
                    strategy: `既然已達成全面優化，下一步可以追求<b>「影響力最大化」</b>。從已改善的指標中，找出改善幅度最大或市場最關注的項目（如減碳），將其作為您對外溝通的核心亮點。`
                };
            } else if (worsenings > improvements && improvements === 0) {
                profile = {
                    title: '系統性退化',
                    icon: 'fa-triangle-exclamation',
                    style: 'style="background-color: rgba(var(--bs-danger-rgb), 0.1); border-left: 5px solid var(--bs-danger);"',
                    insight: `警示：本次迭代導致了<b>系統性的表現惡化</b>，所有 ${worsenings} 項指標均劣於基準設計。這可能源於錯誤的材料選擇、數據輸入錯誤或不恰當的設計變更。`,
                    strategy: `您的首要任務是<b>「停損與覆盤」</b>。立即暫停此設計方向，並回溯BOM變更，找出導致全面退化的根本原因。在問題釐清前，不建議繼續推進此版本。`
                };
            } else if (co2_pct_change < -5) {
                profile = {
                    title: '意外的負面後果',
                    icon: 'fa-user-secret',
                    style: 'style="background-color: rgba(var(--bs-danger-rgb), 0.15); border-left: 5px solid var(--bs-danger);"',
                    insight: `高度警示：本次迭代可能旨在優化其他方面，卻導致了核心氣候指標<b>「總碳足跡」惡化了 ${Math.abs(co2_pct_change).toFixed(0)}%</b>。這是一種高風險的「隱性碳洩漏」，可能損害您的氣候承諾。`,
                    strategy: `您的策略應是<b>「緊急校準」</b>。必須重新評估此次設計變更的必要性，並優先尋找不會對氣候目標產生負面衝擊的替代方案。任何以增加碳排為代價的「優化」都需被嚴格審視。`
                };
            } else {
                profile = {
                    title: '目標明確的微調',
                    icon: 'fa-wrench',
                    style: 'style="background-color: var(--bs-secondary-bg);"',
                    insight: `這是一次小範圍的、目標集中的設計微調。整體變化不大，但在 ${improvements} 個方面取得了小幅進步，並在 ${worsenings} 個方面有輕微取捨。`,
                    strategy: `持續的小步微調是產品生命週期管理中的常見實踐。建議將此次變更作為記錄，並持續監控，以確保多次微調的累積效應符合整體的永續發展藍圖。`
                };
            }

            return `
    <div class="card shadow-sm mb-4 border-0" ${profile.style}>
        <div class="card-body">
            <h5 class="card-title mb-3"><i class="fas ${profile.icon} me-2"></i>比較畫像：${profile.title}</h5>
            <p class="small"><strong><i class="fas fa-search-plus text-primary me-2"></i>量化洞察：</strong>${profile.insight}</p>
            <p class="small mb-0"><strong><i class="fas fa-sitemap text-success me-2"></i>策略建議：</strong>${profile.strategy}</p>
        </div>
    </div>`;
        }

        /**
         * 【V6.0 BOM差異化儀表板 專家版】產生比較儀表板的靜態 HTML
         * @description 整合全新的「BOM 差異化分析儀表板」，提供逐項、多維度的物料變更比對功能。
         * @param {Array} data - 包含兩個報告結果的陣列 [d1, d2]
         * @returns {string} - 儀表板的完整 HTML
         */
        function generateComparativeStaticHTML(data) {
            const [d1, d2] = data;

            // --- KPI 計算邏輯 ---
            const metrics = [
                { key: 'co2', path: 'impact.co2', name: '總碳足跡', unit: 'kg CO₂e', fixed: 3, lowerIsBetter: true, icon: 'fa-smog' },
                { key: 'recycled_pct', path: null, name: '再生料佔比', unit: '%', fixed: 1, lowerIsBetter: false, icon: 'fa-recycle' },
                { key: 'cost', path: 'impact.cost', name: '材料總成本', unit: '$', fixed: 2, lowerIsBetter: true, icon: 'fa-dollar-sign' },
                { key: 's_score', path: 'social_impact.overall_risk_score', name: '社會風險分數', unit: '', fixed: 1, lowerIsBetter: true, icon: 'fa-users' },
                { key: 'g_score', path: 'governance_impact.overall_risk_score', name: '治理風險分數', unit: '', fixed: 1, lowerIsBetter: true, icon: 'fa-landmark' },
                { key: 'totalWeight', path: 'inputs.totalWeight', name: '總重量', unit: 'kg', fixed: 3, lowerIsBetter: true, icon: 'fa-weight-hanging' },
            ];
            const getProp = (obj, path) => path ? path.split('.').reduce((o, i) => o && o[i], obj) : null;
            const comparisonResults = metrics.map(metric => {
                let v1 = (metric.key === 'recycled_pct') ? ((d1.charts.content_by_type.recycled / d1.inputs.totalWeight * 100) || 0) : (getProp(d1, metric.path) || 0);
                let v2 = (metric.key === 'recycled_pct') ? ((d2.charts.content_by_type.recycled / d2.inputs.totalWeight * 100) || 0) : (getProp(d2, metric.path) || 0);
                const diff = v2 - v1;
                const pct = (v1 !== 0) ? (diff / Math.abs(v1) * 100) : (diff > 0 ? Infinity : 0);
                let isImprovement = null;
                if (Math.abs(pct) >= 0.01) { isImprovement = metric.lowerIsBetter ? (diff < 0) : (diff > 0); }
                return { ...metric, v1, v2, diff, pct, isImprovement };
            });

            // --- HTML 產生邏輯 ---
            const verdictHtml = generateComparisonVerdict(comparisonResults);

            const comparisonCardsHtml = comparisonResults.map(metric => {
                const { v1, v2, diff, pct, isImprovement, name, unit, fixed, icon } = metric;
                let main_color_var = 'var(--bs-secondary)';
                let subtle_bg_style = '';
                if (isImprovement === true) { main_color_var = 'var(--primary)'; subtle_bg_style = `style="background-color: rgba(var(--primary-rgb), 0.1);"`; }
                else if (isImprovement === false) { main_color_var = 'var(--bs-danger)'; subtle_bg_style = `style="background-color: rgba(var(--bs-danger-rgb), 0.1);"`; }
                const change_icon = isImprovement === null ? 'fa-minus' : (isImprovement ? 'fa-arrow-down' : 'fa-arrow-up');
                const formatValue = (value, fixed) => (Math.abs(value) > 10000 || (Math.abs(value) < 0.001 && value !== 0)) ? value.toExponential(2) : value.toFixed(fixed);
                const max_val = Math.max(Math.abs(v1), Math.abs(v2), 0.00001);
                return `<div class="col"><div class="card h-100 shadow-sm" ${subtle_bg_style}><div class="card-body d-flex flex-column"><h6 class="card-title text-muted fw-normal mb-1"><i class="fas ${icon} fa-fw me-2" style="color: ${main_color_var}; opacity: 0.75;"></i>${name}</h6><div class="mt-auto"><div class="d-flex justify-content-between align-items-baseline"><div class="fw-bold display-6" style="color: ${main_color_var};">${formatValue(v2, fixed)}</div><div class="badge rounded-pill" style="background-color: ${main_color_var}; font-size: 0.9rem;"><i class="fas ${change_icon} me-1"></i>${isFinite(pct) ? pct.toFixed(1) + '%' : 'N/A'}</div></div><div class="text-end text-muted small">${unit}</div><div class="mt-2 pt-2 border-top"><div class="d-flex justify-content-between small text-muted"><span>報告 B (新設計)</span><span>報告 A (基準): ${formatValue(v1, fixed)}</span></div><div class="progress" style="height: 6px;"><div class="progress-bar" role="progressbar" style="width: ${(Math.abs(v2)/max_val)*100}%; background-color: ${main_color_var};"></div></div><div class="progress mt-1" style="height: 6px;"><div class="progress-bar bg-secondary opacity-50" role="progressbar" style="width: ${(Math.abs(v1)/max_val)*100}%"></div></div></div></div></div></div></div>`;
            }).join('');

            // 呼叫新的 BOM 差異化分析儀表板產生器
            const bomDiffHtml = generateBomDiffView(data);

            // 組合最終的儀表板 HTML
            return `
        <div class="container-fluid py-3">
            <div class="row mb-3 border-bottom pb-3">
                <div class="col-6">
                    <h5 class="text-muted">報告 A (基準)</h5>
                    <h4 class="mb-0">${escapeHtml(d1.versionName || '未命名報告')}</h4>
                </div>
                <div class="col-6">
                    <h5 class="text-muted">報告 B (比較對象)</h5>
                    <h4 class="mb-0">${escapeHtml(d2.versionName || '未命名報告')}</h4>
                </div>
            </div>

            ${verdictHtml}

            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
                ${comparisonCardsHtml}
            </div>

            <div id="bom-diff-container" class="mt-4">
                ${bomDiffHtml}
            </div>

            <hr class="my-4">

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-crosshairs me-2"></i>比較性環境指紋 (雷達圖)</h6>
                        </div>
                        <div class="card-body" style="min-height: 400px;">
                            <canvas id="comparativeRadarChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-exchange-alt me-2"></i>BOM 變更 vs. 碳排影響</h6>
                        </div>
                        <div class="card-body" style="min-height: 400px;">
                            <canvas id="bomDeltaChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
        }

        /**
         * 【V6.1 錯誤修復版】產生 BOM 差異化分析儀表板的 HTML
         * @description 修正了 formatDelta 輔助函式中 "Assignment to constant variable" 的錯誤。
         * @param {Array} data - 包含兩個報告結果的陣列 [d1, d2]
         * @returns {string} - 儀表板的完整 HTML
         */
        function generateBomDiffView(data) {
            const [d1, d2] = data;
            const bom1 = d1.inputs.components.reduce((acc, c) => { acc[c.materialKey] = c; return acc; }, {});
            const bom2 = d2.inputs.components.reduce((acc, c) => { acc[c.materialKey] = c; return acc; }, {});

            const allKeys = [...new Set([...Object.keys(bom1), ...Object.keys(bom2)])];

            let changes = { added: 0, removed: 0, modified: 0 };
            let tableRowsHtml = '';

            allKeys.forEach(key => {
                const c1 = bom1[key];
                const c2 = bom2[key];
                const m = getMaterialByKey(key) || { name: `未知物料 (${key})` };

                let status = 'unchanged';
                if (c1 && !c2) { status = 'removed'; changes.removed++; }
                else if (!c1 && c2) { status = 'added'; changes.added++; }
                else if (c1 && c2) {
                    if (Math.abs(c1.weight - c2.weight) > 1e-9 || Math.abs(c1.percentage - c2.percentage) > 1e-9 || c1.cost !== c2.cost) {
                        status = 'modified';
                        changes.modified++;
                    }
                }

                const v1 = {
                    weight: parseFloat(c1?.weight || 0),
                    recycled: parseFloat(c1?.percentage || 0),
                    co2: d1.charts.composition.find(i => i.key === key)?.co2 || 0
                };
                const v2 = {
                    weight: parseFloat(c2?.weight || 0),
                    recycled: parseFloat(c2?.percentage || 0),
                    co2: d2.charts.composition.find(i => i.key === key)?.co2 || 0
                };

                const delta = {
                    weight: v2.weight - v1.weight,
                    recycled: v2.recycled - v1.recycled,
                    co2: v2.co2 - v1.co2
                };

                // ▼▼▼ 【核心修正】修正此處的 formatDelta 函式 ▼▼▼
                const formatDelta = (value, fixed, lowerIsBetter) => {
                    if (Math.abs(value) < 1e-9) return `<span class="badge bg-secondary-subtle text-secondary-emphasis">--</span>`;

                    const icon = value > 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                    let color = ''; // 使用 let 而不是 const

                    if (lowerIsBetter) {
                        color = value > 0 ? 'text-danger' : 'text-success'; // 數值增加是壞事 (紅色)
                    } else {
                        color = value > 0 ? 'text-success' : 'text-danger'; // 數值增加是好事 (綠色)
                    }

                    return `<span class="${color}"><i class="fas ${icon} fa-xs"></i> ${Math.abs(value).toFixed(fixed)}</span>`;
                };
                // ▲▲▲ 修正結束 ▲▲▲

                const rowClass = { added: 'table-success', removed: 'table-danger', modified: 'table-warning' }[status] || '';
                const statusIcon = { added: '<i class="fas fa-plus text-success"></i>', removed: '<i class="fas fa-minus text-danger"></i>', modified: '<i class="fas fa-pen text-warning"></i>' }[status] || '<i class="fas fa-equals text-muted"></i>';

                tableRowsHtml += `
            <tr class="${rowClass}">
                <td>${statusIcon} ${escapeHtml(m.name)}</td>
                <td class="${Math.abs(delta.weight) > 1e-9 ? 'cell-changed' : ''}">${v1.weight.toFixed(3)}kg &rarr; ${v2.weight.toFixed(3)}kg</td>
                <td class="text-center">${formatDelta(delta.weight, 3, true)}</td>
                <td class="${Math.abs(delta.recycled) > 1e-9 ? 'cell-changed' : ''}">${v1.recycled.toFixed(1)}% &rarr; ${v2.recycled.toFixed(1)}%</td>
                <td class="text-center">${formatDelta(delta.recycled, 1, false)}%</td>
                <td class="text-center">${formatDelta(delta.co2, 3, true)}</td>
            </tr>
        `;
            });

            if (changes.added === 0 && changes.removed === 0 && changes.modified === 0) {
                return '';
            }

            return `
    <div class="card shadow-sm">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-tasks text-primary me-2"></i>BOM 差異化分析儀表板</h5></div>
        <div class="card-body">
            <div class="d-flex justify-content-around text-center mb-3 pb-3 border-bottom">
                <div><h6 class="text-muted">變更總數</h6><p class="fs-4 fw-bold mb-0">${changes.added + changes.removed + changes.modified}</p></div>
                <div><h6 class="text-muted">新增組件</h6><p class="fs-4 fw-bold text-success mb-0">${changes.added}</p></div>
                <div><h6 class="text-muted">移除組件</h6><p class="fs-4 fw-bold text-danger mb-0">${changes.removed}</p></div>
                <div><h6 class="text-muted">修改組件</h6><p class="fs-4 fw-bold text-warning mb-0">${changes.modified}</p></div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered bom-diff-table">
                    <thead class="table-light">
                        <tr>
                            <th rowspan="2" class="align-middle">組件名稱</th>
                            <th colspan="2" class="text-center">重量</th>
                            <th colspan="2" class="text-center">再生比例</th>
                            <th rowspan="2" class="text-center align-middle">碳排影響 (kg CO₂e)</th>
                        </tr>
                        <tr>
                            <th style="min-width: 180px;">A &rarr; B</th>
                            <th class="text-center">變化量</th>
                            <th style="min-width: 150px;">A &rarr; B</th>
                            <th class="text-center">變化量</th>
                        </tr>
                    </thead>
                    <tbody>${tableRowsHtml}</tbody>
                </table>
            </div>
        </div>
    </div>
    `;
        }

        /**
         * 【V3.0 全新】繪製法規財務風險圖表
         */
        function drawFinancialRiskChart(data) {
            const canvasId = 'financialRiskChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.map(r => r.label),
                    datasets: [{
                        label: '潛在成本 (TWD)',
                        data: data.map(r => r.cost),
                        backgroundColor: [
                            THEMES[$('html').attr('data-theme')].chartColors[4] + 'B3', // 橘色系
                            THEMES[$('html').attr('data-theme')].chartColors[3] + 'B3'  // 黃色系
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            title: { display: true, text: '潛在成本 (TWD)' }
                        }
                    }
                }
            });
        }

        /**
         * 【V8.1 UI升級版】繪製 S&G 風險矩陣氣泡圖，並產生整合性 AI 洞察
         * @param {object} socialData - 社會面向的計算結果
         * @param {object} governanceData - 治理面向的計算結果
         * @param {string} narrativeContainerId - 要顯示 AI 洞察的 DOM 容器 ID
         */
        function drawSgRiskMatrixChart(socialData, governanceData, narrativeContainerId) {
            const canvasId = 'sgRiskMatrixChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            const s_contrib = socialData.risk_contribution || [];
            const g_contrib = governanceData.risk_contribution || [];
            const combined = {};

            s_contrib.forEach(item => {
                combined[item.name] = { ...combined[item.name], name: item.name, s_score: item.risk_score, s_weighted: item.weighted_risk };
            });
            g_contrib.forEach(item => {
                combined[item.name] = { ...combined[item.name], name: item.name, g_score: item.risk_score, g_weighted: item.weighted_risk };
            });

            const totalWeightedRisk = s_contrib.reduce((s, i) => s + i.weighted_risk, 0) + g_contrib.reduce((s, i) => s + i.weighted_risk, 0);

            const chartData = Object.values(combined).map(item => {
                const total_weighted_item = (item.s_weighted || 0) + (item.g_weighted || 0);
                return {
                    x: item.s_score || 0,
                    y: item.g_score || 0,
                    r: totalWeightedRisk > 0 ? (total_weighted_item / totalWeightedRisk * 50) + 8 : 10, // 氣泡大小基於風險貢獻度
                    name: item.name
                };
            });

            if (chartData.length === 0) {
                $(ctx.canvas).parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted">無足夠數據繪製風險矩陣。</div>');
                $(narrativeContainerId).text('數據不足，無法生成 AI 洞察。');
                return;
            }

            // AI 智慧洞察生成
            let insight = '';
            const dangerZoneItems = chartData.filter(d => d.x >= 50 && d.y >= 50).sort((a,b) => b.r - a.r);
            const highSRiskItems = chartData.filter(d => d.x >= 50 && d.y < 50).sort((a,b) => b.r - a.r);
            const highGRiskItems = chartData.filter(d => d.x < 50 && d.y >= 50).sort((a,b) => b.r - a.r);

            if (dangerZoneItems.length > 0) {
                insight = `<strong>策略警示：</strong>您的供應鏈中存在系統性風險熱點。特別是「<strong class="text-danger">${escapeHtml(dangerZoneItems[0].name)}</strong>」，它同時暴露在高社會與高治理風險中，應列為最高優先級的管理對象，立即啟動供應商盡職調查。`;
            } else if (highSRiskItems.length > 0) {
                insight = `<strong>策略定位：</strong>主要的供應鏈風險來自社會(S)面向。其中，「<strong class="text-warning">${escapeHtml(highSRiskItems[0].name)}</strong>」是最大的貢獻者。建議加強對其勞工權益、社區影響等社會議題的稽核。`;
            } else if (highGRiskItems.length > 0) {
                insight = `<strong>策略定位：</strong>主要的供應鏈風險來自治理(G)面向。其中，「<strong class="text-secondary">${escapeHtml(highGRiskItems[0].name)}</strong>」是最大的貢獻者。建議強化對其商業道德與供應鏈透明度的管理。`;
            } else {
                insight = `<strong>策略總評：</strong>恭喜！您目前的物料組合在S&G風險上表現穩健，所有組件均落在相對安全的象限內，具備良好的供應鏈聲譽基礎。`;
            }

            $(narrativeContainerId).html(insight);

            // Chart.js 繪圖設定
            const themeConfig = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];
            charts[canvasId] = new Chart(ctx, {
                type: 'bubble',
                data: {
                    datasets: [{
                        label: '物料風險定位',
                        data: chartData,
                        backgroundColor: 'rgba(var(--primary-rgb), 0.6)',
                        borderColor: 'rgba(var(--primary-rgb), 1)',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            title: { display: true, text: '社會(S)風險 →' },
                            min: 0,
                            max: 100
                        },
                        y: {
                            title: { display: true, text: '治理(G)風險 →' },
                            min: 0,
                            max: 100
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: ctx => ` ${ctx.raw.name}`,
                                afterLabel: ctx => `S-Risk: ${ctx.raw.x.toFixed(1)}, G-Risk: ${ctx.raw.y.toFixed(1)}`
                            }
                        },
                        annotation: {
                            annotations: {
                                xLine: {
                                    type: 'line',
                                    xMin: 50,
                                    xMax: 50,
                                    borderColor: 'rgba(var(--bs-danger-rgb), 0.3)',
                                    borderWidth: 2,
                                    borderDash: [6, 6]
                                },
                                yLine: {
                                    type: 'line',
                                    yMin: 50,
                                    yMax: 50,
                                    borderColor: 'rgba(var(--bs-danger-rgb), 0.3)',
                                    borderWidth: 2,
                                    borderDash: [6, 6]
                                },
                                safeZone: {
                                    type: 'label',
                                    xValue: 25,
                                    yValue: 25,
                                    content: '安全區',
                                    color: 'rgba(var(--bs-success-rgb), 0.7)',
                                    font: { size: 12 }
                                },
                                dangerZone: {
                                    type: 'label',
                                    xValue: 75,
                                    yValue: 75,
                                    content: '高危區',
                                    color: 'rgba(var(--bs-danger-rgb), 0.7)',
                                    font: { size: 12, weight: 'bold' }
                                }
                            }
                        }
                    }
                }
            });
        }

        /**
         * [V5.3] 繪製 BOM 變更 vs 碳排影響圖
         */
        function drawBomDeltaChart(data) {
            const { d1, d2, bomChanges } = data;
            const canvasId = 'bomDeltaChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();
            const significantChanges = bomChanges.filter(c => c.type !== 'unchanged').sort((a,b) => Math.abs(b.co2_impact) - Math.abs(a.co2_impact));
            if (significantChanges.length === 0) {
                $(ctx.canvas).parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted">兩份報告的物料清單完全相同。</div>');
                return;
            }
            const labels = significantChanges.map(c => {
                if(c.type === 'added') return `[+] ${c.name}`;
                if(c.type === 'removed') return `[-] ${c.name}`;
                return `[~] ${c.name}`;
            });
            const impactData = significantChanges.map(c => -c.co2_impact);
            const theme = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];
            const colors = impactData.map(val => val >= 0 ? 'rgba(25, 135, 84, 0.8)' : 'rgba(220, 53, 69, 0.8)');
            const config = { type: 'bar', data: { labels: labels, datasets: [{ label: '對總碳足跡的影響 (kg CO₂e)', data: impactData, backgroundColor: colors }] }, options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { title: { display: true, text: '碳排影響 (kg CO₂e) → [正是改善]' } } } } };
            charts[canvasId] = new Chart(ctx, config);
        }

        /**
         * 【全新】繪製商業儀表板的成本結構環圈圖
         */
        function drawCostBreakdownDoughnutChart(data) {
            const canvasId = 'costBreakdownDoughnutChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            const theme = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];
            const chartData = {
                labels: ['材料成本', '製造成本', '管銷/其他'],
                datasets: [{
                    data: [data.material, data.manufacturing, data.sga],
                    backgroundColor: [ theme.chartColors[0], theme.chartColors[1], theme.chartColors[2] ],
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg'),
                    borderWidth: 4,
                }]
            };

            charts[canvasId] = new Chart(ctx, {
                type: 'doughnut',
                data: chartData,
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '60%',
                    plugins: { legend: { position: 'right', labels: { boxWidth: 12 } } }
                }
            });
        }

        /**
         * [V5.3] 繪製比較性環境指紋雷達圖
         */
        function drawComparativeRadarChart(data) {
            const [d1, d2] = data;
            const canvasId = 'comparativeRadarChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();
            const themeConfig = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];
            const labels = ['氣候變遷', '酸化', '優養化', '臭氧層破壞', '光化學煙霧', '能源消耗', '水資源消耗'];
            const extractScores = (scores) => [ scores.co2, scores.acidification, scores.eutrophication, scores.ozone_depletion, scores.photochemical_ozone, scores.energy, scores.water ];
            const config = { type: 'radar', data: { labels: labels, datasets: [ { label: escapeHtml(d1.versionName || '報告 A'), data: extractScores(d1.environmental_fingerprint_scores), backgroundColor: 'rgba(108, 117, 125, 0.2)', borderColor: 'rgba(108, 117, 125, 0.5)', pointBackgroundColor: 'rgba(108, 117, 125, 1)', borderWidth: 1.5, }, { label: escapeHtml(d2.versionName || '報告 B'), data: extractScores(d2.environmental_fingerprint_scores), backgroundColor: themeConfig.chartColors[0] + '4D', borderColor: themeConfig.chartColors[0], pointBackgroundColor: themeConfig.chartColors[0], borderWidth: 2, } ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { r: { angleLines: { color: themeConfig.chartFontColor + '20' }, grid: { color: themeConfig.chartFontColor + '20' }, pointLabels: { color: themeConfig.chartFontColor, font: { size: 12 } }, ticks: { backdropColor: 'transparent', color: themeConfig.chartFontColor + '99', stepSize: 25, font: { size: 10 } }, min: 0, max: 100 } } } };
            charts[canvasId] = new Chart(ctx, config);
        }


        /**
         * 【V3.2 執行順序修正版 - 製程複選升級】從 localStorage 讀取狀態並還原到頁面
         */
        function loadState() {
            const stateJSON = localStorage.getItem('ecoCalculatorState');
            if (stateJSON) {
                const state = JSON.parse(stateJSON);

                if (state.projectId) {
                    setTimeout(() => {
                        $('#projectSelector').val(state.projectId);
                        $('#projectSelector').trigger('change');
                    }, 500);
                }
                $('#newProjectName').val(state.newProjectName);
                $('#versionName').val(state.versionName);
                $('#productionQuantity').val(state.quantity || 1);
                if (state.eol) {
                    $('#eolRecycle').val(state.eol.recycle);
                    $('#eolIncinerate').val(state.eol.incinerate);
                    $('#eolLandfill').val(state.eol.landfill);
                }

                if (state.use_phase_enabled) {
                    $('#enableUsePhase').prop('checked', true);
                    if(state.use_phase_values){
                        $('#usePhaseLifespan').val(state.use_phase_values.lifespan);
                        $('#usePhaseKwh').val(state.use_phase_values.kwh);
                        $('#usePhaseGrid').val(state.use_phase_values.region);
                        $('#usePhaseWater').val(state.use_phase_values.water);
                    }
                }
                if (state.transport_phase_enabled) {
                    $('#enableTransportPhase').prop('checked', true);
                    setTimeout(() => {
                        $('#globalTransportRoute').val(state.transport_global_route || 'none');
                    }, 100);
                }

                toggleUsePhaseInputs();
                toggleTransportPhase();

                $('#materials-list-container').empty();
                if (state.components && state.components.length > 0) {
                    state.components.forEach(c => {
                        if (c.componentType === 'material') {
                            addMaterialRow(c);
                        } else if (c.componentType === 'process') {
                            // addProcessRow 內部已能處理 appliedToComponentKey 是陣列的情況
                            addProcessRow(c);
                        }
                    });
                } else {
                    addMaterialRow();
                }

                updateTotalWeight();
                validateEol();

                // 【核心修正】在所有組件都載入完成後，才根據狀態決定是否觸發一次計算
                if (state.use_phase_enabled || state.transport_phase_enabled) {
                    setTimeout(triggerCalculation, 100);
                }

            } else {
                addMaterialRow();
                updateTotalWeight();
                validateEol();
            }
        }

        /**
         * 【V12.12 - 錯誤修正版】產生一個物料組件列的 HTML
         * @description 移除了 'weight' 和 'percentage' 輸入框的 'required' 屬性，以解決瀏覽器 'not focusable' 的錯誤。
         */
        function getMaterialRowHTML(rowIndex, data = {}) {
            const material = getMaterialByKey(data.materialKey) || {name: '請選擇物料...', data_source: '', cost_per_kg: ''};

            const transportRouteData = (typeof data.transportRoute === 'object' && data.transportRoute !== null)
                ? escapeHtml(JSON.stringify(data.transportRoute))
                : escapeHtml(data.transportRoute || '');

            // 【⬇️ 核心修正】將 remove-material-btn 修正為 remove-component-btn
            return `<div class="material-row"
                         data-index="${rowIndex}"
                         data-key="${escapeHtml(data.materialKey || '')}"
                         data-transport-route="${escapeHtml(data.transportRoute || '')}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold">組件 #${rowIndex + 1}</span>
                    <button type="button" class="btn-close remove-component-btn" aria-label="移除"></button>
                </div>
                <div class="mb-2">
                    <button type="button" class="btn change-material-btn w-100 d-flex justify-content-between align-items-center">
                        <span class="material-name-display">${escapeHtml(material.name)} <small class="text-muted">${escapeHtml(material.data_source)}</small></span>
                        <i class="fas fa-chevron-down fa-xs"></i>
                    </button>
                </div>
                <div class="row g-2">
                    <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">重量(kg)</span><input type="number" class="form-control material-weight" step="0.001" min="0.001" value="${data.weight || ''}" placeholder="e.g. 0.125"></div></div>
                    <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">再生比例(%)</span><input type="number" class="form-control material-percentage" min="0" max="100" value="${data.percentage || 0}"></div></div>
                    <div class="col-12 mt-2"><div class="input-group input-group-sm"><span class="input-group-text">單位成本($/kg)</span><input type="number" class="form-control material-cost" step="0.01" min="0" value="${data.cost || ''}" placeholder="您的實際採購單價 ($/kg)"><button class="btn btn-outline-secondary suggest-cost-btn" type="button" title="填入資料庫參考成本"><i class="fas fa-info-circle"></i></button></div></div>
                </div>
            </div>`;
        }

        // 【核心修改】將 `addMaterialRow` 的邏輯稍微調整，並建立一個新的 `addProcessRow`
        function addMaterialRow(data = {}) {
            const newIndex = $('#materials-list-container').children().length;
            // 增加 data-component-type="material" 屬性
            const rowHtml = getMaterialRowHTML(newIndex, data).replace('<div class="material-row"', '<div class="material-row" data-component-type="material"');
            $('#materials-list-container').append(rowHtml);
            updateTotalWeight();
            updateAllAppliedToSelectors(); // 【重要】新增物料後，更新所有製程的「作用於」選單
        }

        /**
         * 【V12.11 - 註解與功能還原版】新增製程列
         */
        function addProcessRow(data = {}) {
            const container = $('#materials-list-container');
            const newIndex = container.children().length;
            const template = document.getElementById('process-row-template').content.cloneNode(true);
            const newRow = $(template.querySelector('.process-row'));

            newRow.attr('data-index', newIndex);
            newRow.find('.fw-bold').text(`製程 #${newIndex + 1}`);

            const quantityInput = newRow.find('.process-quantity');
            const unitDisplay = newRow.find('.process-unit-display');
            const descriptionPlaceholder = newRow.find('.process-description-placeholder');

            if (data.processKey) {
                const process = ALL_PROCESSES.find(p => p.process_key === data.processKey);
                if (process) {
                    newRow.attr('data-key', data.processKey);
                    newRow.find('.process-name-display').text(process.name);

                    // 【新增】處理製程說明
                    if (process.description) {
                        const tooltipIcon = `<i class="fas fa-question-circle text-muted" data-bs-toggle="popover" data-bs-trigger="hover" title="製程說明" data-bs-content="${escapeHtml(process.description)}"></i>`;
                        descriptionPlaceholder.html(tooltipIcon);
                    } else {
                        descriptionPlaceholder.empty();
                    }

                    if (process.unit && process.unit.toLowerCase() === 'kg') {
                        quantityInput.prop('disabled', true).val('').attr('placeholder', '依物料重量');
                        unitDisplay.text('kg');
                    } else {
                        quantityInput.prop('disabled', false).val(data.quantity || 1);
                        unitDisplay.text(process.unit || '個');
                    }

                    const optionsContainer = newRow.find('.process-options-container');
                    let optionsHtml = '<div class="row g-2">';
                    if(process.options){
                        for (const optKey in process.options) {
                            const option = process.options[optKey];
                            optionsHtml += `<div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">${escapeHtml(option.name)}</span><select class="form-select form-select-sm process-option-select" data-option-key="${optKey}">`;
                            option.choices.forEach(choice => {
                                const isSelected = data.selectedOptions && data.selectedOptions[optKey] === choice.key;
                                optionsHtml += `<option value="${escapeHtml(choice.key)}" ${isSelected ? 'selected' : ''}>${escapeHtml(choice.name)}</option>`;
                            });
                            optionsHtml += `</select></div></div>`;
                        }
                    }
                    optionsHtml += '</div>';
                    optionsContainer.html(optionsHtml);
                }
            } else {
                quantityInput.prop('disabled', true);
            }

            container.append(newRow);

            // 初始化 TomSelect (已移除舊的全選外掛)
            const appliedToSelector = newRow.find('.applied-to-selector')[0];
            new TomSelect(appliedToSelector, {
                placeholder: "選擇作用的物料組件...",
                plugins: { 'remove_button': { title: '移除此項目' } }
            });

            updateAllAppliedToSelectors();
            if (data.appliedToComponentKey && Array.isArray(data.appliedToComponentKey)) {
                if (appliedToSelector.tomselect) {
                    appliedToSelector.tomselect.setValue(data.appliedToComponentKey);
                }
            }

            // 【新增】重新初始化新加入的 Popover
            newRow.find('[data-bs-toggle="popover"]').popover();
        }

        // 【全新功能】使用事件委派，監聽動態產生的「全選」按鈕
        $(document).on('click', '.select-all-applied-to-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();

            // 找到這個按鈕對應的 TomSelect 實例
            const tomSelectWrapper = $(this).closest('.ts-dropdown').prev('.ts-wrapper');
            const selectInput = tomSelectWrapper.find('select.applied-to-selector')[0];

            if (selectInput && selectInput.tomselect) {
                const ts = selectInput.tomselect;
                // 取得所有可選的物料 key
                const allOptionKeys = Object.keys(ts.options);
                // 將 TomSelect 的值設為所有選項
                ts.setValue(allOptionKeys);
                // 選擇後自動關閉下拉選單
                ts.close();
            }
        });

        // 【全新功能】監聽獨立的「全選」與「清除」按鈕
        $('#materials-list-container').on('click', '.apply-to-all-btn, .clear-apply-to-btn', function() {
            const isSelectAll = $(this).hasClass('apply-to-all-btn');
            const select = $(this).closest('.input-group').find('select.applied-to-selector')[0];

            if (select && select.tomselect) {
                const ts = select.tomselect;
                if (isSelectAll) {
                    // 全選：取得所有可選的物料 key 並設定
                    const allOptionKeys = Object.keys(ts.options);
                    ts.setValue(allOptionKeys);
                } else {
                    // 清除：清空所有選項
                    ts.clear();
                }
            }
        });

        /**
         * 【全新功能 - TomSelect 修正版】更新所有「作用於」下拉選單的選項
         * - 確保能正確地對 TomSelect 實例進行操作
         */
        function updateAllAppliedToSelectors() {
            const materialOptions = [];
            // 遍歷所有物料列，建立選項
            $('.material-row').each(function() {
                const key = $(this).data('key');
                const name = $(this).find('.material-name-display').text().split('<small>')[0].trim();
                // 這裡的 value 使用物料的唯一 key，才能跟儲存邏輯對應
                if (key) {
                    materialOptions.push({ value: key, text: name });
                }
            });

            // 更新每一個製程列的 TomSelect
            $('.process-row').each(function() {
                const selector = $(this).find('.applied-to-selector')[0];
                // 【核心修正】確保 tomselect 實例存在，這是與 TomSelect 互動的關鍵
                if (selector && selector.tomselect) {
                    const currentValue = selector.tomselect.getValue(); // 取得當前選定的值
                    selector.tomselect.clearOptions(); // 清空舊選項
                    selector.tomselect.addOptions(materialOptions); // 加入新選項

                    // 如果舊的選定值仍然存在於新選項中，則保留它的選定狀態
                    if (materialOptions.some(opt => opt.value === currentValue)) {
                        selector.tomselect.setValue(currentValue, true); // true 表示靜默設置，不觸發 change 事件
                    }
                }
            });
        }

        /**
         * 【V12.11 - 註解與功能還原版】更新一個已存在的製程列
         */
        function updateProcessRow(index, newProcessKey) {
            const row = $(`.process-row[data-index="${index}"]`);
            const newProcess = ALL_PROCESSES.find(p => p.process_key === newProcessKey);

            if (row.length && newProcess) {
                // 1. 保存舊的「作用於」選項
                const appliedToSelector = row.find('.applied-to-selector')[0];
                const oldAppliedToValues = appliedToSelector.tomselect ? appliedToSelector.tomselect.getValue() : [];

                // 2. 更新 DOM 上的基本資訊
                row.data('key', newProcessKey).attr('data-key', newProcessKey);
                row.find('.process-name-display').text(newProcess.name);

                // 3. 更新製程說明
                const descriptionPlaceholder = row.find('.process-description-placeholder');
                if (newProcess.description) {
                    const tooltipIcon = `<i class="fas fa-question-circle text-muted" data-bs-toggle="popover" data-bs-trigger="hover" title="製程說明" data-bs-content="${escapeHtml(newProcess.description)}"></i>`;
                    descriptionPlaceholder.html(tooltipIcon);
                } else {
                    descriptionPlaceholder.empty();
                }

                // 4. 重新產生該製程的動態選項
                const optionsContainer = row.find('.process-options-container');
                let optionsHtml = '<div class="row g-2">';
                if (newProcess.options) {
                    for (const optKey in newProcess.options) {
                        const option = newProcess.options[optKey];
                        optionsHtml += `<div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">${escapeHtml(option.name)}</span><select class="form-select form-select-sm process-option-select" data-option-key="${optKey}">`;
                        option.choices.forEach(choice => {
                            // 預設選擇第一個選項
                            optionsHtml += `<option value="${escapeHtml(choice.key)}">${escapeHtml(choice.name)}</option>`;
                        });
                        optionsHtml += `</select></div></div>`;
                    }
                }
                optionsHtml += '</div>';
                optionsContainer.html(optionsHtml);

                // 5. 更新數量欄位的狀態
                const quantityInput = row.find('.process-quantity');
                const unitDisplay = row.find('.process-unit-display');

                if (newProcess.unit && newProcess.unit.toLowerCase() === 'kg') {
                    quantityInput.prop('disabled', true).val('').attr('placeholder', '依物料重量');
                    unitDisplay.text('kg');
                } else {
                    quantityInput.prop('disabled', false).val(1); // 更換製程後，數量重設為 1
                    unitDisplay.text(newProcess.unit || '個');
                }

                // 6. 恢復「作用於」的選項
                if (appliedToSelector.tomselect) {
                    appliedToSelector.tomselect.setValue(oldAppliedToValues);
                }

                // 7. 重新初始化新加入的 Popover
                row.find('[data-bs-toggle="popover"]').popover('dispose').popover();

                // 8. 儲存狀態並重新計算
                saveState();
                triggerCalculation();
            }
        }

        function updateMaterialRow(index, newMaterialKey) {
            const material = getMaterialByKey(newMaterialKey);
            const row = $(`.material-row[data-index="${index}"]`);
            if (material && row.length) {
                row.data('key', newMaterialKey);
                row.find('.material-name-display').html(`${escapeHtml(material.name)} <small class="text-muted">${escapeHtml(material.data_source)}</small>`);

                row.find('.material-cost').val(material.cost_per_kg || '');

                saveState();
                // 觸發一次重量和狀態更新，並重新計算
                updateTotalWeight();
                triggerCalculation();
            }
        }

        function populateMaterialBrowser(searchTerm = '') {
            searchTerm = searchTerm.trim().toLowerCase();
            let filtered = searchTerm ? ALL_MATERIALS.filter(m => m.name.toLowerCase().includes(searchTerm) || m.key.toLowerCase().includes(searchTerm)) : ALL_MATERIALS;
            const grouped = filtered.reduce((acc, m) => {
                const category = m.category || '未分類';
                if (!acc[category]) acc[category] = [];
                acc[category].push(m);
                return acc;
            }, {});

            let html = '<div class="list-group list-group-flush">';
            for (const category in grouped) {
                html += `<h6 class="text-muted ps-3 pt-3 mb-1">${escapeHtml(category)}</h6>`;
                html += grouped[category].map(m => `<a href="#" class="list-group-item list-group-item-action" data-key="${escapeHtml(m.key)}">${escapeHtml(m.name)} <small class="text-muted">(${escapeHtml(m.key)})</small></a>`).join('');
            }
            html += '</div>';
            $('#material-browser-list').html(html || '<p class="text-center text-muted p-3">找不到符合條件的物料。</p>');
        }

        function showLoading(isShowing, text = '處理中...') { $('#loading-text').text(text); $('#loading-overlay').css('display', isShowing ? 'flex' : 'none'); }
        function displayError(message) { $('#results-panel-wrapper').hide(); $('#initial-message').show().find('h2').text('分析時發生錯誤').next('p').text(message); }

        function drawChart(canvasId, config) {
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();
            charts[canvasId] = new Chart(ctx, config);
        }

        function drawEnvironmentalFingerprintChart(canvasId, scores) {
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            const themeConfig = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];
            const data = [
                scores.co2, scores.acidification, scores.eutrophication,
                scores.ozone_depletion, scores.photochemical_ozone,
                scores.energy, scores.water
            ].map(score => (score + 100) / 2); // <--- 新增此行

            const radarConfig = {
                type: 'radar',
                data: {
                    labels: ['氣候變遷', '酸化', '優養化', '臭氧層破壞', '光化學煙霧', '能源消耗', '水資源消耗'],
                    datasets: [{
                        label: '改善成效分數', data: data, fill: true,
                        backgroundColor: themeConfig.chartColors[0] + '66',
                        borderColor: themeConfig.chartColors[0],
                        pointBackgroundColor: themeConfig.chartColors[0],
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: themeConfig.chartColors[0]
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: {
                        r: {
                            angleLines: { color: themeConfig.chartColors[5] + '40' },
                            grid: { color: themeConfig.chartColors[5] + '40' },
                            pointLabels: { color: themeConfig.chartFontColor, font: { size: 12 } },
                            ticks: { backdropColor: 'transparent', color: themeConfig.chartFontColor + '99', stepSize: 25, font: { size: 10 } },
                            min: 0, max: 100
                        }
                    },
                    plugins: { legend: { display: false } }
                }
            };
            charts[canvasId] = new Chart(ctx, radarConfig);
        }


        // ===================================================================
        // START: 深度剖析模組 - 繪圖/敘事/UI控制函式
        // ===================================================================

        // --- 輔助函式 ---
        function getMaterialCategoryByKey(key) {
            const material = ALL_MATERIALS.find(m => m.key === key);
            return material ? (material.category || '未分類') : '未分類';
        }

        function buildNarrativeBlock(title, insight, strategy, advice) {
            let html = `<h6 class="mb-3">診斷定位：<span class="badge fs-6 text-primary bg-primary-subtle border border-primary-subtle"><i class="fas fa-lightbulb me-2"></i>${title}</span></h6>`;
            html += `<p class="small text-muted mb-3">${insight}</p>`;
            html += `<h6><i class="fas fa-sitemap text-success me-2"></i>策略意涵</h6><ul class="list-group list-group-flush small mb-3"><li class="list-group-item p-2">${strategy}</li></ul>`;
            html += `<h6><i class="fas fa-bullseye text-danger me-2"></i>行動建議</h6><ul class="list-group list-group-flush small"><li class="list-group-item p-2">${advice}</li></ul>`;
            return html;
        }

        // --- 1. 永續性深度剖析 (Sustainability Deep Dive) ---
        let currentAct = 1;
        const totalActs = 3;
        const actPanes = ['#pane-macro', '#pane-positioning', '#pane-tracing'];
        const actTitles = ['宏觀掃描：解構材料基因', '精準定位：鎖定衝擊熱點', '路徑追溯：量化減碳貢獻'];

        function showAct(actNumber) {
            currentAct = actNumber;
            $('#act-progress-bar').css('width', (currentAct / totalActs * 100) + '%').attr('aria-valuenow', currentAct);
            const topic = ['deep-dive-macro', 'deep-dive-hotspot', 'deep-dive-pathway'][currentAct - 1];
            $('#act-indicator').html(`${actTitles[currentAct - 1]}<i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="${topic}" title="這代表什麼？"></i>`);
            $('#advancedAnalysisTabContent .tab-pane').removeClass('show active');
            $(actPanes[currentAct - 1]).addClass('show active');
            $('#prev-act-btn').prop('disabled', currentAct === 1);
            $('#next-act-btn').prop('disabled', currentAct === totalActs).html(currentAct === totalActs ? '<i class="fas fa-check me-2"></i>完成診斷' : '繼續分析<i class="fas fa-arrow-right ms-2"></i>');
        }

        function prepareCategoryData(compositionData) {
            const categorySummary = {};
            if (!compositionData || !Array.isArray(compositionData)) { return categorySummary; }
            compositionData.forEach(component => {
                if (component && component.key) {
                    const category = getMaterialCategoryByKey(component.key);
                    if (!categorySummary[category]) { categorySummary[category] = { weight: 0, co2: 0 }; }
                    categorySummary[category].weight += (parseFloat(component.weight) || 0);
                    categorySummary[category].co2 += (parseFloat(component.co2) || 0);
                }
            });
            return categorySummary;
        }

        function drawCategoryCharts(categoryData) {
            const canvasId = 'categoryCombinedChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;

            const themeColors = THEMES[$('html').attr('data-theme')].chartColors;
            const labels = Object.keys(categoryData);

            // 檢查是否有有效的數據可供繪製
            if (labels.length === 0) {
                $(ctx.canvas).parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted p-3 text-center">無足夠數據進行物料大類分析。</div>');
                if (charts[canvasId]) {
                    charts[canvasId].destroy();
                    delete charts[canvasId];
                }
                return;
            }

            const totalWeight = Object.values(categoryData).reduce((sum, d) => sum + d.weight, 0);
            const totalCo2 = Object.values(categoryData).reduce((sum, d) => sum + d.co2, 0);
            const config = { type: 'doughnut', data: { labels: labels, datasets: [{ label: '重量 (kg)', data: labels.map(l => categoryData[l].weight), backgroundColor: themeColors.map(c => c + 'E6'), borderColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg'), borderWidth: 3 }, { label: '碳排 (kg CO₂e)', data: labels.map(l => categoryData[l].co2), backgroundColor: themeColors.map(c => c + 'B3'), borderColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg'), borderWidth: 3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function(ctx) { const percentage = ctx.datasetIndex === 0 ? (ctx.raw / totalWeight * 100) : (ctx.raw / totalCo2 * 100); return `${ctx.label} (${ctx.dataset.label}): ${ctx.raw.toFixed(2)} (${percentage.toFixed(1)}%)`; } } } } } };
            drawChart(canvasId, config);
        }

        function prepareMatrixData(compositionData, totalWeight) {
            if (!compositionData || compositionData.length === 0 || totalWeight <= 0) return { datasets: [], avgX: 0, avgY: 0 };
            const datasets = compositionData.map(c => ({ x: (c.weight / totalWeight) * 100, y: c.weight > 0 ? (c.co2 / c.weight) : 0, r: Math.max(5, Math.sqrt(Math.abs(c.co2) / Math.PI) * 15), label: c.name, co2: c.co2 }));
            const avgX = datasets.reduce((sum, d) => sum + d.x, 0) / datasets.length;
            const avgY = datasets.reduce((sum, d) => sum + d.y, 0) / datasets.length;
            return { datasets, avgX, avgY };
        }

        function drawImpactMatrixChart(matrixData) {
            const canvasId = 'impactMatrixChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;

            const { datasets, avgX, avgY } = matrixData;

            // 將原本的 return; 替換為更友善的提示訊息
            if (datasets.length === 0) {
                $(ctx.canvas).parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted p-3 text-center">無足夠數據繪製衝擊矩陣圖。</div>');
                if (charts[canvasId]) {
                    charts[canvasId].destroy();
                    delete charts[canvasId];
                }
                return;
            }

            const getColor = d => { if (d.x > avgX && d.y > avgY) return 'rgba(220, 53, 69, 0.7)'; if (d.x <= avgX && d.y > avgY) return 'rgba(255, 193, 7, 0.7)'; if (d.x > avgX && d.y <= avgY) return 'rgba(13, 202, 240, 0.7)'; return 'rgba(25, 135, 84, 0.7)'; };
            const config = { type: 'bubble', data: { datasets: [{ label: '物料組件', data: datasets, backgroundColor: datasets.map(getColor) }] }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { title: { display: true, text: '重量佔比 (%) →' }, min: 0 }, y: { title: { display: true, text: '衝擊密度 (kg CO₂e / kg) →' }, min: 0 } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `${ctx.raw.label}: ${ctx.raw.co2.toFixed(3)} kg CO₂e`, afterLabel: ctx => `重量佔比: ${ctx.raw.x.toFixed(1)}%, 衝擊密度: ${ctx.raw.y.toFixed(2)}` } }, annotation: { annotations: { avgXLine: { type: 'line', xMin: avgX, xMax: avgX, borderColor: 'rgba(108, 117, 125, 0.5)', borderWidth: 1, borderDash: [6, 6] }, avgYLine: { type: 'line', yMin: avgY, yMax: avgY, borderColor: 'rgba(108, 117, 125, 0.5)', borderWidth: 1, borderDash: [6, 6] } } } } } };
            drawChart(canvasId, config);
        }

        function prepareWaterfallData(compositionData, totalCo2) {
            const sortedData = [...compositionData].sort((a, b) => b.co2 - a.co2); const labels = ['(初始值)']; const dataPoints = [[0, 0]]; let cumulative = 0;
            sortedData.forEach(c => { labels.push(c.name); dataPoints.push([cumulative, cumulative + c.co2]); cumulative += c.co2; });
            labels.push('總計'); dataPoints.push([0, totalCo2]); return { labels, dataPoints };
        }

        function drawWaterfallChart(waterfallData) {
            const { labels, dataPoints } = waterfallData; if (!labels || labels.length === 0) return;
            const theme = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];
            const colors = dataPoints.map((p, i) => (i === 0 || i === dataPoints.length - 1) ? theme.chartColors[0] : (p[1] > p[0] ? 'rgba(220, 53, 69, 0.7)' : 'rgba(25, 135, 84, 0.7)'));
            const config = { type: 'bar', data: { labels: labels, datasets: [{ label: '碳足跡 (kg CO₂e)', data: dataPoints, backgroundColor: colors, borderColor: colors.map(c => c.replace('0.7', '1')), borderWidth: 1 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { const raw = ctx.raw; const value = raw[1] - raw[0]; const label = ctx.label || ''; if (label === '(初始值)' || label === '總計') return `${label}: ${raw[1].toFixed(3)} kg CO₂e`; return `${label}: ${value >= 0 ? '+' : ''}${value.toFixed(3)} kg CO₂e`; } } } }, scales: { y: { title: { display: true, text: '累積碳足跡 (kg CO₂e)' } } } } };
            drawChart('waterfallChart', config);
        }



        // --- 2. 成本效益深度剖析 (Cost-Benefit Deep Dive) ---
        let currentCostAct = 1; const totalCostActs = 3;
        const costActPanes = ['#cost-pane-macro', '#cost-pane-positioning', '#cost-pane-comparison'];
        const costActTitles = ['宏觀掃描：鳥瞰成本結構', '精準定位：連結成本與碳排', '效益分析：量化綠色ROI'];

        function showCostAct(actNumber) {
            currentCostAct = actNumber;
            $('#cost-act-progress-bar').css('width', (currentCostAct / totalCostActs * 100) + '%').attr('aria-valuenow', currentCostAct);
            const topic = ['cost-deep-dive-macro', 'cost-deep-dive-positioning', 'cost-deep-dive-comparison'][currentCostAct - 1];
            $('#cost-act-indicator').html(`${costActTitles[currentCostAct - 1]}<i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="${topic}" title="這代表什麼？"></i>`);
            $('#costAnalysisTabContent .tab-pane').removeClass('show active');
            $(costActPanes[currentCostAct - 1]).addClass('show active');
            $('#prev-cost-act-btn').prop('disabled', currentCostAct === 1);
            $('#next-cost-act-btn').prop('disabled', currentCostAct === totalCostActs).html(currentCostAct === totalCostActs ? '<i class="fas fa-check me-2"></i>完成診斷' : '繼續分析<i class="fas fa-arrow-right ms-2"></i>');
        }

        function prepareCostCompositionData(compositionData) {
            if (!compositionData) return { labels: [], data: [] };
            const totalCost = compositionData.reduce((sum, c) => sum + (c.cost || 0), 0);
            if (totalCost <= 0) return { labels: [], data: [] };
            const labels = compositionData.map(c => c.name);
            const data = compositionData.map(c => c.cost);
            return { labels, data, totalCost };
        }

        function drawCostCompositionChart(chartData) {
            const canvasId = 'costCompositionChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;

            const { labels, data } = chartData;

            if (labels.length === 0) {
                $(ctx.canvas).parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted p-3 text-center">無成本數據可進行組成分析。<br><small>請在左側面板為組件輸入單位成本。</small></div>');
                if (charts[canvasId]) {
                    charts[canvasId].destroy();
                    delete charts[canvasId];
                }
                return;
            }

            const themeColors = THEMES[$('html').attr('data-theme')].chartColors;
            const config = {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '成本貢獻',
                        data: data,
                        backgroundColor: themeColors.map(c => c + 'E6'),
                        borderColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg'),
                        borderWidth: 3,
                    }]
                },
                options: getDoughnutChartOptions('成本組成分析')
            };
            drawChart(canvasId, config);
        }

        function prepareCostCarbonData(compositionData) {
            if (!compositionData) return { datasets: [] };
            const datasets = compositionData.filter(c => c.cost > 0 && c.co2 > 0)
                .map(component => {
                    const radius = Math.max(5, Math.sqrt(component.weight / Math.PI) * 15);
                    return {x: component.co2, y: component.cost, r: radius, label: component.name};
                });
            return { datasets };
        }

        function prepareCostComparisonData(compositionData) {
            if (!compositionData) return { labels: [], currentCosts: [], virginCosts: [] };
            const labels = compositionData.map(c => c.name);
            const currentCosts = compositionData.map(c => c.cost);
            const virginCosts = compositionData.map(c => c.cost_virgin);
            return { labels, currentCosts, virginCosts };
        }

        function drawCostCarbonChart(chartData) {

            const datasets = chartData.datasets;
            if (!datasets || datasets.length === 0) {
                $('#costCarbonMatrixChart').parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted">無足夠數據繪製成本與碳排關聯圖。</div>');
                return;
            }
            const config = {
                type: 'bubble',
                data: {
                    datasets: [{
                        label: '物料組件',
                        data: datasets,
                        backgroundColor: 'rgba(25, 135, 84, 0.7)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { title: { display: true, text: '碳足跡貢獻 (kg CO₂e) →' }, min: 0 },
                        y: { title: { display: true, text: '成本貢獻 ($) →' }, min: 0 }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.raw.label,
                                afterLabel: ctx => `成本: ${ctx.raw.y.toFixed(2)} 元, 碳排: ${ctx.raw.x.toFixed(3)} kg CO₂e`
                            }
                        }
                    }
                }
            };
            drawChart('costCarbonMatrixChart', config);
        }

        function drawCostComparisonChart(compositionData) {
            if (!compositionData || !compositionData.labels) {
                $('#costComparisonChart').parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted">無足夠數據進行成本比較。</div>');
                return;
            }

            const config = {
                type: 'bar',
                data: {
                    labels: compositionData.labels,
                    datasets: [{
                        label: '100%原生料 成本',
                        data: compositionData.virginCosts,
                        backgroundColor: 'rgba(108, 117, 125, 0.5)'
                    }, {
                        label: '您的產品 成本',
                        data: compositionData.currentCosts,
                        backgroundColor: THEMES[$('html').attr('data-theme')].chartColors[0] + '99'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true }
                    },
                    plugins: {
                        title: { display: false }
                    }
                }
            };
            drawChart('costComparisonChart', config);
            // ▲▲▲【修改完畢】▲▲▲
        }


        function generateCostMacroNarrative(compositionData, totalCost) {
            let title = '分析中...', insight = '', strategy = '', advice = '';
            if (totalCost > 0) {
                const topSpender = [...compositionData].sort((a, b) => (b.cost || 0) - (a.cost || 0))[0];
                const topCostPct = (topSpender.cost / totalCost) * 100;
                if (topCostPct > 50) {
                    title = '診斷：成本高度集中'; insight = `您的產品成本結構呈現顯著的帕雷托效應。<b>「${escapeHtml(topSpender.name)}」</b>這單一組件的成本就佔了總材料成本的 <b>${topCostPct.toFixed(1)}%</b>。`; strategy = `這意味著您的產品總成本對「${escapeHtml(topSpender.name)}」的價格波動極為敏感。`; advice = `您的成本優化策略應完全聚焦於此財務熱點。建議立即啟動針對「${escapeHtml(topSpender.name)}」的價值工程分析（Value Engineering）。`;
                } else {
                    title = '診斷：成本分佈健康'; insight = `您的產品成本分佈較為均勻，最高的<b>「${escapeHtml(topSpender.name)}」</b>也僅佔總成本的 <b>${topCostPct.toFixed(1)}%</b>。`; strategy = '健康的成本結構意味著您的產品對於單一零件的價格波動具有較強的抵抗力（Resilience）。'; advice = '既然宏觀成本結構良好，您可以將注意力轉向下一幕：這些成本與環境衝擊之間的關聯性如何？';
                }
            }
            $('#narrative-cost-macro').html(buildNarrativeBlock(title, insight, strategy, advice));
        }

        function generateCostPositioningNarrative(compositionData) {
            let title = '分析中...', insight = '', strategy = '', advice = '';
            const valuableData = compositionData.filter(c => c.cost > 0 && c.co2 > 0);
            if (valuableData.length > 0) {
                const avgCost = valuableData.reduce((sum, c) => sum + c.cost, 0) / valuableData.length;
                const avgCo2 = valuableData.reduce((sum, c) => sum + c.co2, 0) / valuableData.length;
                const winWin = valuableData.filter(c => c.cost > avgCost && c.co2 > avgCo2).sort((a, b) => b.cost - a.cost);
                if (winWin.length > 0) {
                    title = '診斷：發現 Win-Win 機會點'; insight = `數據顯示，<b>「${escapeHtml(winWin[0].name)}」</b>是典型的「雙重熱點」（高成本、高碳排）。`; strategy = `這類組件是您實現「永續性投資」的最佳標的，任何對其進行的優化，都有潛力同時降低財務和環境成本。`; advice = `請將「${escapeHtml(winWin[0].name)}」列為最優先改善項目。與供應商合作，探討使用更環保的替代方案，很可能帶來意想不到的成本節省。`;
                } else {
                    title = '診斷：成本與碳排表現良好'; insight = '您的產品在成本與碳排之間取得了良好的平衡，沒有出現「雙重熱點」組件。'; strategy = '這反映出您的產品在設計和採購階段，已經內隱地對成本和環境效益進行了權衡。'; advice = '下一步的關鍵是量化您的永續策略到底帶來了多少財務效益。請進入下一幕「效益分析」。';
                }
            }
            $('#narrative-cost-positioning').html(buildNarrativeBlock(title, insight, strategy, advice));
        }

        function generateCostComparisonNarrative(compositionData, totalCost, virginTotalCost) {
            let title = '分析中...', insight = '', strategy = '', advice = '';
            if (totalCost > 0 && virginTotalCost > 0) {
                const costDiff = virginTotalCost - totalCost;
                if (costDiff > 0) {
                    title = '診斷：綠色策略帶來成本節省'; insight = `恭喜！相較於100%使用原生材料的設計，您目前的永續策略成功地為每件產品節省了 <b>${costDiff.toFixed(2)} 元</b> 的材料成本。`; strategy = '這個數據是「永續性等於高成本」這一迷思的有力反駁，證明了您的綠色策略是一項明智的商業決acetamide'; advice = '請將這個「綠色ROI」數據作為您內部溝通的關鍵亮點，以爭取更多資源來擴大永續材料的使用。';
                } else {
                    title = '診斷：為永續性付出的綠色溢價'; insight = `數據顯示，為了達成環境效益，您的設計比100%原生料的設計，每件產品增加了 <b>${Math.abs(costDiff).toFixed(2)} 元</b> 的材料成本。這就是您的「綠色溢價」(Green Premium)。`; strategy = '這個溢價是您為產品的永續性、品牌價值和社會責任所做的具體投資，精準地量化這個數字，有助於您制定合理的定價策略。'; advice = '您的任務是向市場溝通這個溢價背後的價值，並持續與供應鏈合作，尋求降低這個溢價的機會。';
                }
            }
            $('#narrative-cost-comparison').html(buildNarrativeBlock(title, insight, strategy, advice));
        }

        // --- 3. 環境韌性深度剖析 (Environmental Resilience Deep Dive) [修正後版本] ---
        let currentResilienceAct = 1;
        const totalResilienceActs = 3;
        const resilienceActPanes = ['#resilience-pane-1', '#resilience-pane-2', '#resilience-pane-3'];
        const resilienceActTitles = ['第一幕：整體診斷', '第二幕：根本原因探勘', '第三幕：權衡與決策'];

        function showResilienceAct(actNumber) {
            currentResilienceAct = actNumber;
            $('#resilience-act-progress-bar').css('width', (currentResilienceAct / totalResilienceActs * 100) + '%').attr('aria-valuenow', currentResilienceAct);
            const topic = ['resilience-act1', 'resilience-act2', 'resilience-act3'][currentResilienceAct - 1];
            $('#resilience-act-indicator').html(`${resilienceActTitles[currentResilienceAct - 1]}<i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="${topic}" title="這代表什麼？"></i>`);

            $('#resilienceAnalysisTabContent .tab-pane').removeClass('show active');
            $(resilienceActPanes[currentResilienceAct - 1]).addClass('show active');

            $('#prev-resilience-act-btn').prop('disabled', currentResilienceAct === 1);
            $('#next-resilience-act-btn').prop('disabled', currentResilienceAct === totalResilienceActs).html(currentResilienceAct === totalResilienceActs ? '<i class="fas fa-check me-2"></i>完成診斷' : '繼續分析<i class="fas fa-arrow-right ms-2"></i>');
        }

        /**
         * 【V7.2 全新整合版 - 雙軌制邏輯】繪製「權衡矩陣圖」或顯示替代資訊
         * @param {object} hotspotData - 來自後端的 multi_criteria_hotspots 數據
         * @param {string} weakPointKey - 第一幕中識別出的最弱環境衝擊的 key (e.g., 'acidification')
         * @param {string} primaryMetric - (可選) 主要比較指標，預設為 'co2'
         */
        function drawTradeoffMatrixChart(hotspotData, weakPointKey, primaryMetric = 'co2') {
            const canvasId = 'tradeoffMatrixChart';
            const chartContainer = $(`#${canvasId}`).parent(); // 取得 canvas 的容器
            const ctx = document.getElementById(canvasId)?.getContext('2d');

            // 【核心升級】先檢查容器是否存在
            if (chartContainer.length === 0) return;

            // 清理舊的圖表實例
            if (charts[canvasId]) {
                charts[canvasId].destroy();
                delete charts[canvasId];
            }

            // 【核心升級】判斷是否進入特殊情境
            if (weakPointKey === primaryMetric) {
                // 情境B：最弱點就是核心目標，不繪製圖表，改為顯示說明文字
                const htmlContent = `
            <div class="d-flex align-items-center justify-content-center h-100 text-center text-muted p-3">
                <div>
                    <i class="fas fa-bullseye fa-3x text-primary mb-3"></i>
                    <h5>聚焦核心問題</h5>
                    <p class="small">由於產品最主要的環境挑戰即為「${scoreLabels[weakPointKey]}」，此階段無需進行權衡分析。請專注於 AI 洞察中提供的策略建議。</p>
                </div>
            </div>
        `;
                chartContainer.html(htmlContent);
                return; // 中止函式，不繪製圖表
            }

            // 情境A：正常情況，繼續繪製權衡矩陣圖
            const componentsForWeakPoint = hotspotData?.[weakPointKey]?.components;

            if (!componentsForWeakPoint || componentsForWeakPoint.length === 0) {
                chartContainer.html('<div class="d-flex align-items-center justify-content-center h-100 text-muted p-3 text-center">無法繪製權衡矩陣圖，因為在此衝擊類別下沒有找到顯著的貢獻來源。</div>');
                return;
            }

            const weakPointLabel = scoreLabels[weakPointKey] || weakPointKey;
            const primaryMetricLabel = '碳足跡貢獻 (%)';
            const datasets = perUnitData.charts.composition.map(c => {
                const weakPointImpact = hotspotData[weakPointKey].components.find(h => h.name === c.name)?.value || 0;
                const primaryImpactPct = hotspotData[primaryMetric]?.components.find(h => h.name === c.name)?.percent || 0;
                return { x: weakPointImpact, y: primaryImpactPct, r: Math.max(5, Math.sqrt(c.weight / Math.PI) * 15), label: c.name };
            }).filter(d => d.x !== 0 || d.y !== 0);

            // 如果 canvas 被文字取代後又需要重繪，需重新建立 canvas
            if (chartContainer.find('canvas').length === 0) {
                chartContainer.html(`<canvas id="${canvasId}"></canvas>`);
            }
            const newCtx = document.getElementById(canvasId).getContext('2d');

            const config = { type: 'bubble', data: { datasets: [{ label: '組件', data: datasets, backgroundColor: 'rgba(131, 56, 236, 0.7)' }] }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { title: { display: true, text: `${weakPointLabel}貢獻 →` }, ticks: { callback: val => val.toExponential(1) } }, y: { title: { display: true, text: `${primaryMetricLabel} →` }, min: 0 } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ctx.raw.label, afterLabel: ctx => `X: ${ctx.raw.x.toExponential(2)}, Y: ${ctx.raw.y.toFixed(1)}%` } } } } };
            charts[canvasId] = new Chart(newCtx, config);
        }

        // index.php (JavaScript 區塊)

        /**
         * 【V7.2 全新整合版 - 最終強化版】產生「環境指紋深度剖析」模組的三幕劇策略解讀
         * @description 最終版：第三幕新增「雙軌制」邏輯，能應對「最弱點即為碳足跡」的特殊情境。
         */
        function generateResilienceNarratives(data) {
            const scoresArray = Object.values(data.environmental_fingerprint_scores);
            const scoresEntries = Object.entries(data.environmental_fingerprint_scores);
            const minScore = scoresEntries.reduce((min, score) => score[1] < min[1] ? score : min, scoresEntries[0]);
            const maxScore = scoresEntries.reduce((max, score) => score[1] > max[1] ? score : max, scoresEntries[0]);
            const avgScore = scoresArray.reduce((a, b) => a + b, 0) / scoresArray.length;
            const stdDev = Math.sqrt(scoresArray.map(x => Math.pow(x - avgScore, 2)).reduce((a, b) => a + b, 0) / scoresArray.length);
            let profile = {};
            const compositionData = data.charts.composition;

            // --- 單一組件情境的專屬敘事 (已完成，保持不變) ---
            if (compositionData && compositionData.length === 1) {
                // ... 此處省略已完成的單一組件邏輯 ...
                // 為了節省篇幅，此處省略您已更新的程式碼，實際貼上時請包含它
            }

            // --- 多組件情境敘事 ---
            const AVG_HIGH = 65, AVG_LOW = 30; const STDDEV_HIGH = 25;
            if (avgScore >= AVG_HIGH) { profile = (stdDev <= STDDEV_HIGH) ? { name: '全面領導者', color: 'success', icon: 'fa-crown' } : { name: '機會主義領導者', color: 'primary', icon: 'fa-rocket' }; }
            else if (avgScore >= AVG_LOW) { profile = (stdDev <= STDDEV_HIGH) ? { name: '穩健執行者', color: 'info', icon: 'fa-shield-alt' } : { name: '典型的權衡者', color: 'warning', icon: 'fa-balance-scale' }; }
            else { profile = (stdDev <= STDDEV_HIGH) ? { name: '結構性風險暴露', color: 'danger', icon: 'fa-exclamation-triangle' } : { name: '混亂的設計', color: 'danger', icon: 'fa-bomb' }; }

            // --- 第一幕 (邏輯不變) ---
            const insight1 = `您的產品在所有環境面向的平均改善分數為 <b>${avgScore.toFixed(0)}</b>，分數的離散程度 (標準差) 為 <b>${stdDev.toFixed(0)}</b>。`;
            const strategy1 = `此「環境指紋」畫像代表您產品在多維度環境衝擊下的總體策略定位。一個均衡且高分的指紋，代表穩健的永續設計。`;
            const advice1 = `請進入下一幕，我們將聚焦於如何提升表現最弱的環節<b>「${scoreLabels[minScore[0]]}」</b>(分數: ${minScore[1].toFixed(0)})，這是您產品的「阿基里斯之踵」。`;
            const title1 = `第一幕：策略畫像 - <span class="badge fs-6 text-${profile.color} bg-${profile.color}-subtle border border-${profile.color}-subtle"><i class="fas ${profile.icon} me-2"></i>${profile.name}</span>`;
            $('#narrative-resilience-1').html(buildNarrativeBlock(title1, insight1, strategy1, advice1));

            // --- 第二幕 (邏輯不變) ---
            const title2 = '第二幕：根本原因探勘';
            const insight2 = `此「堆疊式熱點分析圖」將第一幕識別出的各項環境議題，解構成由不同「驅動因子」（物料組件）所貢獻的衝擊路徑。`;
            const strategy2 = `此「貢獻度分析」是實踐「80/20法則」於永續設計的關鍵。它讓企業能將有限的研發與採購資源，精準地投入在能產生最大效益的「衝擊熱點」上，避免資源浪費。`;
            const advice2 = `請聚焦於您在第一幕中得分最低的環境議題<b>「${scoreLabels[minScore[0]]}」</b>所對應的垂直長條。其中，<b>佔據最大面積的色塊所代表的物料</b>，即為該衝擊路徑上的「關鍵控制點」。這個物料就是我們在第三幕中需要進行權衡分析的對象。`;
            $('#narrative-resilience-2').html(buildNarrativeBlock(title2, insight2, strategy2, advice2));

            // --- 第三幕：【核心升級】雙軌制敘事邏輯 ---
            const weakPointKey = minScore[0];
            const weakPointLabel = scoreLabels[weakPointKey];
            const mainHotspot = data.charts.multi_criteria_hotspots[weakPointKey]?.components[0];
            let title3 = '第三幕：權衡與決策';
            let insight3, strategy3, advice3;

            if (weakPointKey === 'co2') {
                // 情境B：最弱點就是碳足跡 -> 執行「深度衝擊拆解」
                title3 = '第三幕：深度衝擊拆解';
                if (mainHotspot) {
                    const hotspotName = mainHotspot.name;
                    insight3 = `由於產品最主要的環境弱點就是「氣候變遷」本身，第三幕的分析將聚焦於對這個核心問題的<span class="highlight-term">深度拆解</span>，而非權衡分析。`;
                    strategy3 = `當核心目標與最大弱點重疊時，策略應從「避免副作用」轉向「<span class="highlight-term">集中火力解決主問題</span>」。此刻，所有資源都應聚焦於找出降低總碳足跡的最有效路徑。`;
                    advice3 = `您的首要任務是處理碳排熱點<b>「${escapeHtml(hotspotName)}」</b>。建議返回<b>「氣候行動計分卡」</b>或<b>「成本效益深度剖析」</b>儀表板，對此物料進行更詳細的材料與製程分析，並利用<b>「AI 最佳化引擎」</b>尋找其低碳替代方案。`;
                } else {
                    insight3 = '數據不足，無法進行深度衝擊拆解。';
                    strategy3 = '請確保所有組件都具有完整的碳排數據。';
                    advice3 = '返回第一幕，檢查數據完整性。';
                }
            } else {
                // 情境A：最弱點不是碳足跡 -> 維持原有的「權衡分析」
                if (mainHotspot) {
                    const hotspotName = mainHotspot.name;
                    const co2Hotspot = data.charts.multi_criteria_hotspots['co2']?.components.find(c => c.name === hotspotName);
                    insight3 = `我們已鎖定<b>「${escapeHtml(hotspotName)}」</b>是造成「${weakPointLabel}」問題的關鍵控制點。此「權衡矩陣」旨在評估：若對其進行優化，是否會對公司的核心氣候目標（總碳足跡）產生非預期的負面影響（即「<span class="highlight-term">問題轉移</span>」）。`;
                    strategy3 = `在做出任何設計變更前，進行權衡分析是專業LCA實踐的核心。它將技術決策提升至策略層面，確保解決方案的穩健性，避免為了解決一個問題而創造出另一個更嚴重的問題。`;
                    if (co2Hotspot && co2Hotspot.percent > 10) {
                        advice3 = `<b>決策建議：高優先級行動。</b>分析顯示，優化「${escapeHtml(hotspotName)}」是一個「協同效益」(Synergy)機會點。它不僅能解決「${weakPointLabel}」問題，還能同時顯著降低總碳足跡（貢獻佔比 <b>${co2Hotspot.percent.toFixed(0)}%</b>）。應立即採取行動。`;
                    } else {
                        advice3 = `<b>決策建議：低風險優化。</b>分析顯示，優化「${escapeHtml(hotspotName)}」對總碳足跡的影響有限。這意味著您可以專注於尋找一個在「${weakPointLabel}」衝擊上表現更佳的替代方案，而無需過度擔心它會損害您的核心氣候目標。`;
                    }
                } else {
                    insight3 = '數據不足或衝擊量過低，無法進行權衡分析。';
                    strategy3 = '在做出任何修改之前，進行權衡分析是專業LCA實踐的核心，能防止「衝擊轉移」。';
                    advice3 = '請確認所有組件都具有完整的衝擊數據，或返回第二幕選擇其他衝擊類別進行探勘。';
                }
            }
            $('#narrative-resilience-3').html(buildNarrativeBlock(title3, insight3, strategy3, advice3));

            return profile;
        }

        /**
         * 【V7.2 全新整合版 - 單一組件邏輯強化版】產生「環境指紋深度剖析」模組的三幕劇策略解讀
         */
        function generateSustainabilityNarratives_Expert(data) {
            const compositionData = data.charts.composition;
            const totalWeight = data.inputs.totalWeight;
            const totalCo2 = data.impact.co2;
            let profile = { name: '數據不足', color: 'secondary', icon: 'fa-question-circle' };

            // --- 【核心升級】單一組件情境的專屬敘事 ---
            if (compositionData && compositionData.length === 1) {
                const component = compositionData[0];
                profile = { name: '單一材料產品', color: 'info', icon: 'fa-cube' };

                // 第一幕
                const title1 = `第一幕：材料基因圖譜 - <span class="badge fs-6 text-${profile.color} bg-${profile.color}-subtle border border-${profile.color}-subtle"><i class="fas ${profile.icon} me-2"></i>${profile.name}</span>`;
                const insight1 = `此產品由單一材料「<b>${escapeHtml(component.name)}</b>」構成。其永續表現完全取決於此材料自身的生命週期衝擊。`;
                const strategy1 = `對於單一材料產品，不存在多組件之間的權衡與複雜性。分析的焦點應完全集中在此材料的選擇與其供應鏈管理上。`;
                const advice1 = `請直接進入下一步，我們將深入分析此材料的衝擊密度與減碳路徑。`;
                $('#narrative-macro').html(buildNarrativeBlock(title1, insight1, strategy1, advice1));

                // 第二幕
                const title2 = '診斷：單一材料定位';
                const insight2 = `此產品為單一組件構成，不存在多組件之間的權衡關係。其在矩陣圖上的定位，完全由材料本身的衝擊密度（Y軸）與其構成100%產品重量（X軸）所決定。`;
                const strategy2 = `優化策略非常純粹：降低此材料的「衝擊密度」。`;
                const advice2 = `您的行動方案應聚焦於：1. 提升此材料的再生比例。 2. 尋找本身衝擊密度更低的替代材料。 3. 在滿足功能需求的前提下，進行輕量化設計。`;
                $('#narrative-positioning').html(buildNarrativeBlock(title2, insight2, strategy2, advice2));

                // 第三幕
                const title3 = '診斷：單一路徑碳流';
                const insight3 = `此產品的碳流路徑非常單純，其總碳足跡 <b>${totalCo2.toFixed(3)} kg CO₂e</b> 即為「<b>${escapeHtml(component.name)}</b>」的淨生命週期排放。`;
                const strategy3 = `瀑布圖清晰地展示了此單一材料的總體氣候影響。`;
                const advice3 = `若要改善，您需要尋找能為此瀑布圖增加「綠色長條」（碳信用）的機會，例如確保產品在生命終端能被有效回收。`;
                $('#narrative-tracing').html(buildNarrativeBlock(title3, insight3, strategy3, advice3));

                return profile; // 直接返回，結束函式
            }

            // --- 原有的多組件情境敘事邏輯 (保持不變) ---
            if (!compositionData || compositionData.length === 0 || totalWeight <= 0) {
                const title1_error = `第一幕：材料基因圖譜 - <span class="badge fs-6 text-${profile.color} bg-${profile.color}-subtle border border-${profile.color}-subtle"><i class="fas ${profile.icon} me-2"></i>${profile.name}</span>`;
                $('#narrative-macro').html(buildNarrativeBlock(title1_error, '物料清單中沒有有效的數據來進行宏觀分析。', '請先在左側新增物料並成功執行一次分析。', '確保所有物料的重量都大於零。'));
                $('#narrative-positioning, #narrative-tracing').empty();
            } else {
                const categoryData = prepareCategoryData(compositionData);
                const categories = Object.entries(categoryData).map(([name, values]) => ({ name, ...values }));
                let insight1 = '', strategy1 = '', advice1 = '';

                if (totalCo2 <= 0) {
                    profile = { name: '全方位低衝擊設計', color: 'success', icon: 'fa-star' };
                    insight1 = `您的產品總碳足跡為 <b>${totalCo2.toFixed(3)} kg CO₂e</b>，已達成零碳排甚至「碳負排放」(Carbon Negative)。這是一個從搖籃到大門階段就實現低衝擊的典範。`;
                    strategy1 = `此畫像代表您的產品不僅對氣候友善，更具備頂級的市場競爭優勢與品牌資產，是永續設計的標竿。`;
                    advice1 = `恭喜！您的策略重點應是保護並強化帶來此卓越成果的關鍵因素（例如高回收效益的材料），並將此設計模式與成功經驗標準化，作為未來產品開發的綠色設計規範。`;
                } else if (categories.length > 0) {
                    const hotspotCategory = [...categories].sort((a, b) => b.co2 - a.co2)[0];
                    const dominantCategory = [...categories].sort((a, b) => b.weight - a.weight)[0];

                    if (hotspotCategory && dominantCategory) {
                        const hotspotCo2Pct = (hotspotCategory.co2 / totalCo2) * 100;
                        const dominantWeightPct = (dominantCategory.weight / totalWeight) * 100;
                        const CONCENTRATION_THRESHOLD = 60;

                        if (hotspotCo2Pct >= CONCENTRATION_THRESHOLD) {
                            profile = { name: '關鍵熱點驅動型', color: 'warning', icon: 'fa-crosshairs' };
                            insight1 = `產品的環境衝擊呈現經典的「80/20法則」。<b>「${escapeHtml(hotspotCategory.name)}」</b>這個材料家族貢獻了不成比例的碳排放 (<b>${hotspotCo2Pct.toFixed(0)}%</b>)，但其重量佔比相對較低。`;
                            strategy1 = `這是一個絕佳的優化機會！此畫像意味著您無需對整個產品大動干戈，只需精準地「外科手術式」處理這個關鍵熱點，即可達成顯著的整體改善。`;
                            advice1 = `請將所有資源聚焦於處理這個「隱形殺手」。進入下一幕，我們將從<b>「${escapeHtml(hotspotCategory.name)}」</b>家族中，揪出具體是哪個零組件造成了問題。`;
                        } else if (dominantWeightPct >= CONCENTRATION_THRESHOLD) {
                            profile = { name: '結構性衝擊型', color: 'info', icon: 'fa-weight-hanging' };
                            insight1 = `您的產品主要由相對環保的<b>「${escapeHtml(dominantCategory.name)}」</b>構成（重量佔比 <b>${dominantWeightPct.toFixed(0)}%</b>），其衝擊貢獻與重量貢獻相符，無不成比例的熱點。`;
                            strategy1 = `此產品的環境衝擊主要來自「結構」而非「材料毒性」。這意味著問題不在於「用了什麼」，而在於「用了多少」。您的核心策略應是「設計優化」而非「材料替換」。`;
                            advice1 = `建議與結構工程師合作，針對主體結構進行「輕量化設計」(Lightweighting) 或「拓撲優化」，在不犧牲性能的前提下，減少材料的總用量。`;
                        } else {
                            profile = { name: '複合性衝擊型', color: 'secondary', icon: 'fa-cubes' };
                            insight1 = `產品的衝擊來源與重量構成都較為分散，沒有任何單一材料家族佔據主導地位。這是一個由多種材料共同構成的「複合系統」。`;
                            strategy1 = `此畫像意味著產品不存在單一的「銀色子彈」解決方案。任何顯著的改善，都需要多個組件、多個材料家族的協同優化，這對研發與供應鏈管理提出了更高的要求。`;
                            advice1 = `建議採取多管齊下的策略。請進入下一幕的熱點矩陣圖，從所有組件中識別出相對優先級最高的2-3個項目，作為您第一階段的優化目標。`;
                        }
                    } else {
                        profile = { name: '數據分析異常', color: 'secondary', icon: 'fa-question-circle' };
                        insight1 = `在分析材料類別時遇到數據異常，無法產生宏觀掃描。`;
                        strategy1 = `這可能是由於某些物料的環境影響數據不完整造成的。`;
                        advice1 = `請檢查物料庫中各項材料的數據是否齊全，特別是碳排(CO2)與重量(weight)數據。`;
                    }
                }
                const title1 = `第一幕：材料基因圖譜 - <span class="badge fs-6 text-${profile.color} bg-${profile.color}-subtle border border-${profile.color}-subtle"><i class="fas ${profile.icon} me-2"></i>${profile.name}</span>`;
                $('#narrative-macro').html(buildNarrativeBlock(title1, insight1, strategy1, advice1));
            }

            const matrixData = prepareMatrixData(compositionData, totalWeight);
            let title2 = '診斷中...', insight2 = '...', strategy2 = '...', advice2 = '...';
            if (matrixData.datasets.length > 0) {
                const hotspots = matrixData.datasets.filter(d => d.x > matrixData.avgX && d.y > matrixData.avgY).sort((a,b) => b.co2 - a.co2);
                const killers = matrixData.datasets.filter(d => d.x <= matrixData.avgX && d.y > matrixData.avgY).sort((a,b) => b.co2 - a.co2);
                if (hotspots.length > 0) {
                    title2 = '診斷：帕雷托效應顯著'; insight2 = `您的產品最主要的環境壓力源來自位於<b>「主要熱點」</b>的組件：<b>「${escapeHtml(hotspots[0].label)}」</b>。`; strategy2 = `此矩陣圖證明了您無須改善所有零件，只需將資源精準投入到關鍵熱點上，即可取得最高效的減碳成果。`; advice2 = `您的行動方案非常明確：所有資源應優先集中處理<b>「${escapeHtml(hotspots[0].label)}」</b>。建議立即為其導入高比例再生材料或評估低碳替代方案。`;
                } else if (killers.length > 0) {
                    title2 = '診斷：存在隱性環境負債'; insight2 = `數據顯示，您的產品沒有絕對的熱點，但存在「隱形殺手」，特別是<b>「${escapeHtml(killers[0].label)}」</b>。`; strategy2 = '「隱形殺手」經常在傳統的成本或重量分析中被忽略，但LCA能精準地將其揪出，是從「優秀」邁向「卓越」的關鍵一步。'; advice2 = `您的首要任務是處理這個「隱形殺手」。由於它重量輕，替換它通常是一個高投資報酬率的優化點。`;
                } else {
                    title2 = '診斷：衝擊分佈健康'; insight2 = '所有組件均位於「次要因子」象限，沒有出現顯著的環境熱點。'; strategy2 = '這代表您產品的零組件在重量與衝擊密度兩方面都達到了非常理想的平衡狀態。'; advice2 = '恭喜！在零組件層面已無明顯的優化熱點。您可以自信地將目前的設計作為未來產品的優秀基準。';
                }
            }
            $('#narrative-positioning').html(buildNarrativeBlock(title2, insight2, strategy2, advice2));

            let title3 = '診斷中...', insight3 = '...', strategy3 = '...', advice3 = '...';
            if (compositionData && compositionData.length > 0) {
                const emitters = compositionData.filter(c => c.co2 > 0).sort((a, b) => b.co2 - a.co2);
                const reducers = compositionData.filter(c => c.co2 < 0).sort((a, b) => a.co2 - b.co2);
                if (totalCo2 < -0.001) {
                    title3 = '診斷：實現淨碳移除'; insight3 = `您的產品最終總碳足跡為 <b>${totalCo2.toFixed(3)} kg CO₂e</b>，是一個負值，達到了「碳負排放」(Carbon Negative)。`; strategy3 = '這不僅是環境效益，更是極其強大的市場競爭優勢與品牌資產。'; advice3 = `您的首要任務是保護並強化帶來負排放的關鍵因素，並將此成就作為您最重要的行銷亮點。`;
                } else if (emitters.length > 0 && reducers.length > 0) {
                    title3 = '診斷：英雄與反派的對決'; insight3 = `瀑布圖上演了一場拉鋸戰：<b>「${escapeHtml(emitters[0].name)}」</b>作為最主要的碳排放源，但其衝擊被<b>「${escapeHtml(reducers[0].name)}」</b>所帶來的回收效益有效地抵銷了一部分。`; strategy3 = '這個「有正有負」的複雜碳流，構成了一個引人入勝的減碳故事。'; advice3 = `您的優化策略應雙管齊下：1. <b>削弱反派：</b> 盡力縮短<b>「${escapeHtml(emitters[0].name)}」</b>的紅色長條。2. <b>強化英雄：</b> 思考如何增加<b>「${escapeHtml(reducers[0].name)}」</b>的綠色長條效益。`;
                } else if (emitters.length > 0) {
                    title3 = '診斷：碳排的線性累積'; insight3 = `產品的總碳足跡主要由<b>「${escapeHtml(emitters[0].name)}」</b>等幾個排放源線性疊加而成，缺乏有效的負向抵銷機制。`; strategy3 = `目前的碳流是一個單向的累積過程，缺乏內部循環或生命終端的緩解機制，減碳潛力尚未被充分挖掘。`; advice3 = `建議立即評估引入具有「生命終端碳信用」的材料（例如金屬、PET），或在EOL情境中提高回收率，為您的產品創造出第一個關鍵的綠色長條。`;
                }
            }
            $('#narrative-tracing').html(buildNarrativeBlock(title3, insight3, strategy3, advice3));

            return profile;
        }

        // ===================================================================
        // END: 深度剖析模組 - 繪圖/敘事/UI控制函式
        // ===================================================================
        function getDoughnutChartOptions(title) { return { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' }, title: { display: false, text: title } } }; }
        function getCompositionChartConfig(d) { return { type: 'polarArea', data: { labels: d.map(i => i.name), datasets: [{ data: d.map(i => i.weight), backgroundColor: THEMES[$('html').attr('data-theme')].chartColors.map(c => c + 'B3') }] }, options: getDoughnutChartOptions('單件產品組成 (重量)') }; }
        function getImpactCompareChartConfig(cur, vir) { const l = ['碳足跡(kg CO₂e)', '能源消耗(MJ)', '水資源消耗(L)']; return { type: 'bar', data: { labels: l, datasets: [{ label: '100%原生料', data: Object.values(vir), backgroundColor: 'rgba(108, 117, 125, 0.5)' }, { label: '您的產品', data: Object.values(cur), backgroundColor: THEMES[$('html').attr('data-theme')].chartColors[0] + '99' }] }, options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { title: { display: false } } } }; }
        function getImpactSourceChartConfig(d) { const l = { co2: '碳足跡(kg CO₂e)', energy: '能源消耗(MJ)', water: '水資源消耗(L)' }; const n = Object.keys(d); const colors = THEMES[$('html').attr('data-theme')].chartColors; return { type: 'bar', data: { labels: Object.values(l), datasets: n.map((name, i) => ({ label: name, data: Object.keys(l).map(t => d[name][t]), backgroundColor: colors[i % colors.length] })) }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true } }, plugins: { title: { display: false } } } }; }
        function getImpactAndSavingsChartConfig(d) { const l = Object.keys(d); const colors = THEMES[$('html').attr('data-theme')].chartColors; return { type: 'bar', data: { labels: l, datasets: [{ label: '原生料碳排', data: l.map(i => d[i].co2_from_virgin), backgroundColor: colors[4] + 'B3', stack: 'Actual' }, { label: '再生料碳排', data: l.map(i => d[i].co2_from_recycled), backgroundColor: colors[2] + 'B3', stack: 'Actual' }, { label: '已節省碳排', data: l.map(i => d[i].co2_saved), backgroundColor: colors[0] + 'B3' }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { stacked: true } }, plugins: { title: { display: false } } } }; }
        function getLifecycleChartConfig(d) {
            const theme = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];
            const phaseMapping = [
                { key: 'production', label: '生產製造', color: theme.chartColors[1] },
                { key: 'transport',  label: '運輸', color: theme.chartColors[2] },
                { key: 'use',        label: '使用階段', color: theme.chartColors[3] },
                { key: 'eol',        label: '廢棄處理', color: theme.chartColors[4] }
            ];

            const labels = [];
            const data = [];
            const backgroundColors = [];
            const creditColor = theme.chartColors[0]; // 綠色代表碳信用/效益

            phaseMapping.forEach(phase => {
                // 只要有數值 (無論正負)，就納入圖表
                if (d[phase.key] && Math.abs(d[phase.key]) > 1e-9) {
                    const value = d[phase.key];
                    labels.push(phase.label);

                    // 【核心修改】直接使用原始數值，長條圖可以處理負數
                    data.push(value);

                    // 根據數值的正負決定顏色
                    backgroundColors.push(value < 0 ? creditColor : phase.color);
                }
            });

            if (data.length === 0) {
                // 如果沒有數據，回傳一個空的長條圖結構
                return {
                    type: 'bar',
                    data: { labels: ['無生命週期數據'], datasets: [{ data: [] }] },
                    options: { maintainAspectRatio: false, plugins: { title: { display: true, text: '碳足跡生命週期階段分析' } } }
                };
            }

            // 【核心修改】回傳一個 bar chart 的設定
            return {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '碳足跡貢獻',
                        data: data,
                        backgroundColor: backgroundColors
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false // 單一數據集不需要圖例
                        },
                        title: {
                            display: true,
                            text: '碳足跡生命週期階段分析'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return ` ${context.formattedValue} kg CO₂e`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            title: {
                                display: true,
                                text: '碳足跡 (kg CO₂e)'
                            }
                        }
                    }
                }
            };
        }
        function getContentChartConfig(d) { const colors = THEMES[$('html').attr('data-theme')].chartColors; return { type: 'doughnut', data: { labels: ['再生料', '原生料'], datasets: [{ data: [d.recycled, d.virgin], backgroundColor: [colors[0], colors[5]] }] }, options: getDoughnutChartOptions('產品原料構成分析 (重量kg)') }; }
        function drawRadarChart(data) {
            const ctx = document.getElementById('radarChart')?.getContext('2d'); if (!ctx) return; if (charts['radarChart']) charts['radarChart'].destroy();
            const themeConfig = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];

            // 【核心修正】更新雷達圖的標籤以符合新的「四大策略支柱」模型
            const radarConfig = {
                type: 'radar',
                data: {
                    labels: ['氣候領導力', '循環實踐力', '資源管理力', '衝擊減緩力'],
                    datasets: [{
                        label: '產品表現剖析',
                        data: data, // 數據現在是整合後的四個分數
                        fill: true,
                        backgroundColor: themeConfig.chartColors[0] + '66',
                        borderColor: themeConfig.chartColors[0],
                        pointBackgroundColor: themeConfig.chartColors[0],
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: themeConfig.chartColors[0]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            angleLines: { color: themeConfig.chartColors[5] + '40' },
                            grid: { color: themeConfig.chartColors[5] + '40' },
                            pointLabels: { color: themeConfig.chartFontColor, font: { size: 12 } },
                            ticks: { backdropColor: 'transparent', color: themeConfig.chartFontColor + '99', stepSize: 25, font: { size: 10 } },
                            min: 0,
                            max: 100
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            };
            charts['radarChart'] = new Chart(ctx, radarConfig);
        }
        /**
         * 【V7.0 全新】繪製氣候行動計分卡的子圖表
         */
        function drawLifecycleBreakdownChart(lifecycleData) {
            const canvasId = 'lifecycleBreakdownChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            const theme = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];

            const phaseMapping = {
                production:    { label: '生產製造', color: theme.chartColors[1] },
                transport:     { label: '運輸', color: theme.chartColors[2] },
                use:           { label: '使用階段', color: theme.chartColors[3] },
                eol:           { label: '廢棄處理', color: theme.chartColors[4] },
                sequestration: { label: '生物碳移除', color: theme.chartColors[0] } // 綠色代表正效益
            };

            let labels = [];
            let data = [];
            let backgroundColors = [];

            Object.keys(phaseMapping).forEach(key => {
                if (lifecycleData[key] && Math.abs(lifecycleData[key]) > 1e-9) {
                    labels.push(phaseMapping[key].label);
                    const value = (key === 'sequestration') ? -Math.abs(lifecycleData[key]) : lifecycleData[key];
                    data.push(value);
                    backgroundColors.push(value < 0 ? theme.chartColors[0] : phaseMapping[key].color);
                }
            });

            if (data.length === 0) {
                $(ctx.canvas).parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted">無生命週期數據可顯示。</div>');
                return;
            }

            charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '碳排貢獻 (kg CO₂e)',
                        data: data,
                        backgroundColor: backgroundColors,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return ` ${context.formattedValue} kg CO₂e`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: '碳排貢獻 (kg CO₂e) → [負值為減碳效益]'
                            }
                        }
                    }
                }
            });
        }

        function drawCarbonHotspotChart(compositionData) {
            const canvasId = 'carbonHotspotChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();
            const sorted = [...compositionData].sort((a,b) => (b.co2 ?? 0) - (a.co2 ?? 0)).slice(0, 5);
            charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: sorted.map(c => c.name),
                    datasets: [{
                        label: '碳排貢獻 (kg CO₂e)',
                        data: sorted.map(c => c.co2),
                        backgroundColor: THEMES[$('html').attr('data-theme')].chartColors[0] + 'B3',
                    }]
                },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
        }

        /**
         * 【V7.0 全新】繪製綜合循環經濟計分卡的子圖表
         */
        function drawCircularityBreakdownChart(data, performanceData) {
            const canvasId = 'circularityBreakdownChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            const mci_score = data.circularity_analysis.mci_score ?? 0;
            const waste_score = performanceData.sub_scores_for_debug.waste_score ?? 0;
            const adp_score = data.resource_depletion_impact.performance_score ?? 0;

            charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['MCI (產品循環)', '生產廢棄物', '資源消耗(ADP)'],
                    datasets: [{
                        label: '分數',
                        data: [mci_score, waste_score, adp_score],
                        backgroundColor: [
                            THEMES[$('html').attr('data-theme')].chartColors[0] + 'B3',
                            THEMES[$('html').attr('data-theme')].chartColors[1] + 'B3',
                            THEMES[$('html').attr('data-theme')].chartColors[2] + 'B3'
                        ],
                    }]
                },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { min: 0, max: 100 } } }
            });
        }

        /**
         * 【全新 V1.0】繪製綜合水資源管理計分卡的子圖表
         */
        function drawWaterBreakdownChart(performanceData) {
            const canvasId = 'waterBreakdownChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            const sub_scores = performanceData.sub_scores_for_debug.water_sub_scores;
            const labels = Object.keys(sub_scores);
            const data = Object.values(sub_scores);

            charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '分數',
                        data: data,
                        backgroundColor: THEMES[$('html').attr('data-theme')].chartColors.slice(0, 4).map(c => c + 'B3'),
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x: { min: 0, max: 100 } }
                }
            });
        }

        /**
         * 【V7.0 全新】繪製污染防治計分卡的子圖表
         */
        function drawPollutionBreakdownChart(performanceData) {
            const canvasId = 'pollutionBreakdownChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            const sub_scores = performanceData.sub_scores_for_debug.pollution_sub_scores;
            const labels = Object.keys(sub_scores);
            const data = Object.values(sub_scores);

            charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '分數',
                        data: data,
                        backgroundColor: THEMES[$('html').attr('data-theme')].chartColors.slice(0, 4).map(c => c + 'B3'),
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 100 } } }
            });
        }


        /**
         * 【V6.4 策略四象限版 - 單一組件邏輯強化版】根據結構化分析數據，產生綜合分析卡片的 HTML
         */
        function renderHolisticAnalysisCard(analysis, data) {
            if (!analysis || !analysis.profile || !data || !data.charts || !data.impact || !data.inputs) {
                return '<div class="card"><div class="card-body text-muted">渲染綜合分析卡片時缺少必要數據。</div></div>';
            }

            // 【核心升級】直接使用後端傳來的 advice_html
            const { profile, advice_html } = analysis;
            let insight_html = `<li class="list-group-item p-2"><strong>診斷總評：</strong>${escapeHtml(profile.description)}</li>`;

            const sdgHtml = generateSdgIconsHtml([9, 12, 13]);
            return `
    <div class="card">
        <div class="card-header bg-gradient-start text-white d-flex justify-content-between align-items-center" style="background: var(--primary) !important;">
            <h5 class="mb-0 text-white"><i class="fas fa-microscope me-2"></i> 綜合分析與建議<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5>${sdgHtml}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="holistic-analysis" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-5">
                    <h6>永續策略四象限雷達圖 <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="radar-chart-specific" title="這代表什麼？"></i></h6>
                    <div style="height: 250px;"><canvas id="radarChart"></canvas></div>
                </div>
                <div class="col-lg-7">
                    <h6 class="mb-3">診斷定位：<span class="badge fs-6 text-${profile.color} bg-${profile.color}-subtle border border-${profile.color}-subtle"><i class="fas ${profile.icon} me-2"></i>${escapeHtml(profile.title)}</span></h6>
                    <h6><i class="fas fa-search-plus text-info me-2"></i>量化洞察</h6>
                    <ul class="list-group list-group-flush small mb-3">${insight_html}</ul>
                    <h6><i class="fas fa-bullseye text-danger me-2"></i>策略建議</h6>
                    <ul class="list-group list-group-flush small">${advice_html}</ul>
                </div>
            </div>
        </div>
    </div>`;
        }

        /**
         * 【V8.1 全新】渲染整合式的供應鏈 S&G 風險儀表板
         * @description 此函式作為主控制器，負責填充所有數據並呼叫繪圖函式。
         */
        function renderComprehensiveSgDashboard(socialData, governanceData, sgHotspots) {
            // 1. 填充左側核心指標
            $('#sg-summary-s-score').text(socialData.overall_risk_score.toFixed(1));
            $('#sg-summary-g-score').text(governanceData.overall_risk_score.toFixed(1));

            // 2. 產生 Top 3 風險貢獻來源列表
            const hotspotListContainer = $('#sg-hotspot-list-container');
            if (sgHotspots && sgHotspots.length > 0) {
                let hotspotHtml = '';
                sgHotspots.slice(0, 3).forEach(item => {
                    hotspotHtml += `
                <div>
                    <div class="d-flex justify-content-between small">
                        <span>${escapeHtml(item.name)}</span>
                        <span class="fw-bold">${item.total_risk_pct.toFixed(1)}%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-warning" role="progressbar" style="width: ${item.s_risk_pct}%;" title="社會風險貢獻: ${item.s_risk_pct.toFixed(1)}%"></div>
                        <div class="progress-bar bg-secondary" role="progressbar" style="width: ${item.g_risk_pct}%;" title="治理風險貢獻: ${item.g_risk_pct.toFixed(1)}%"></div>
                    </div>
                </div>`;
                });
                hotspotListContainer.html(hotspotHtml);
            } else {
                hotspotListContainer.html('<p class="small text-muted">無顯著風險貢獻來源。</p>');
            }

            // 3. 呼叫繪圖函式繪製右側的風險矩陣圖
            //    同時傳遞 AI 洞察所需的 DOM 容器 ID
            drawSgRiskMatrixChart(socialData, governanceData, '#sg-comprehensive-narrative');
        }

        /**
         * 【V5.0 全新】渲染環境績效細分儀表板的 HTML
         */
        function renderEnvironmentalPerformanceCard(data) {
            if (!data) return '';
            const { overall_e_score, breakdown } = data;

            const getScoreColor = (score) => {
                if (score >= 75) return 'success';
                if (score >= 50) return 'info';
                if (score >= 25) return 'warning';
                return 'danger';
            };

            const renderSubScore = (label, score, icon, tooltip) => {
                const color = getScoreColor(score);
                return `
                <div class="col">
                    <div class="text-center p-2 rounded-3 bg-light-subtle h-100">
                        <h6 class="small text-muted d-flex align-items-center justify-content-center">${label} <i class="fas fa-question-circle ms-2" data-bs-toggle="tooltip" title="${tooltip}"></i></h6>
                        <p class="fw-bold fs-4 mb-1 text-${color}"><i class="fas ${icon} me-2"></i>${score}</p>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-${color}" style="width: ${score}%;"></div>
                        </div>
                    </div>
                </div>
                `;
            };

            // AI 智慧洞察
            let insight = '';
            const sortedBreakdown = Object.entries(breakdown).sort(([,a],[,b]) => a - b);
            const weakest = sortedBreakdown[0];
            const strongest = sortedBreakdown[sortedBreakdown.length - 1];
            const labels = { climate: '氣候行動', circularity: '循環經濟', water: '水資源管理', pollution: '污染防治', nature: '自然資本' };

            if (weakest[1] < 40) {
                insight = `<strong>策略警示：</strong>產品在「<strong class="text-danger">${labels[weakest[0]]}</strong>」構面表現最為薄弱 (分數: ${weakest[1]})，是您提升整體環境績效時，應最優先處理的短版。`;
            } else {
                insight = `<strong>策略總評：</strong>產品在五大環境構面表現均衡，其中以「<strong class="text-success">${labels[strongest[0]]}</strong>」(分數: ${strongest[1]}) 最為突出。這是一個穩健的設計，可將優勢項目作為您的永續溝通亮點。`;
            }

            return `
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-leaf text-primary me-2"></i>環境績效細部分析儀表板<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5></div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-lg-3 text-center border-end">
                             <h6 class="text-muted">綜合環境分數 (E)</h6>
                             <div class="display-3 fw-bold text-${getScoreColor(overall_e_score)}">${overall_e_score}</div>
                             <p class="small text-muted mt-2">(0-100, 分數越高越好)</p>
                        </div>
                        <div class="col-lg-9">
                            <h6 class="text-muted text-center mb-3">五大環境構面表現</h6>
                            <div class="row row-cols-3 row-cols-md-5 g-2">
                                ${renderSubScore(labels.climate, breakdown.climate, 'fa-smog', '減碳成效')}
                                ${renderSubScore(labels.circularity, breakdown.circularity, 'fa-recycle', '資源利用與廢棄物管理')}
                                ${renderSubScore(labels.water, breakdown.water, 'fa-tint', '水資源消耗與稀缺性衝擊')}
                                ${renderSubScore(labels.pollution, breakdown.pollution, 'fa-biohazard', '各類污染物排放控制')}
                                ${renderSubScore(labels.nature, breakdown.nature, 'fa-paw', '生物多樣性與土地利用衝擊')}
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="p-3 bg-light-subtle rounded-3">
                        <h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6>
                        <p class="small text-muted mb-0">${insight}</p>
                    </div>
                </div>
            </div>`;
        }

        /**
         * 【V6.1 強化版】渲染 S&G 風險熱點圖表 (具備下鑽分析功能)
         */
        function renderSgRiskHotspotChart(sgHotspots) {
            const canvasId = 'sgRiskHotspotChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            if (!sgHotspots || sgHotspots.length === 0) {
                $(ctx.canvas).parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted">無顯著的 S&G 風險來源。</div>');
                return;
            }

            const theme = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];
            charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: sgHotspots.map(item => item.name),
                    datasets: [{
                        label: '社會風險 (S)',
                        data: sgHotspots.map(item => item.s_risk_pct),
                        backgroundColor: 'rgba(255, 193, 7, 0.7)', // Warning color
                        borderColor: 'rgba(255, 193, 7, 1)',
                        borderWidth: 1
                    }, {
                        label: '治理風險 (G)',
                        data: sgHotspots.map(item => item.g_risk_pct),
                        backgroundColor: 'rgba(108, 117, 125, 0.7)', // Secondary color
                        borderColor: 'rgba(108, 117, 125, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true, title: { display: true, text: '對總風險的貢獻度 (%)' } },
                        y: { stacked: true }
                    },
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed.x !== null) { label += context.parsed.x.toFixed(2) + '%'; }
                                    return label;
                                }
                            }
                        }
                    },
                    // ▼▼▼【程式碼修改】▼▼▼
                    // 新增 onClick 事件處理器
                    onClick: (event, elements) => {
                        if (elements.length === 0) return; // 如果沒點到長條，就什麼都不做

                        const clickedIndex = elements[0].index;
                        const materialName = charts[canvasId].data.labels[clickedIndex];

                        // 從總物料庫中找到該物料的完整數據
                        const materialData = ALL_MATERIALS.find(m => m.name === materialName);
                        if (!materialData) return;

                        // 準備要顯示在彈出視窗的內容
                        const parseJsonField = (jsonString) => {
                            try {
                                const arr = JSON.parse(jsonString);
                                return Array.isArray(arr) && arr.length > 0 ? arr : null;
                            } catch { return null; }
                        };

                        const s_risks = parseJsonField(materialData.known_risks);
                        const s_certs = parseJsonField(materialData.certifications);
                        const g_risks = parseJsonField(materialData.identified_risks);
                        const g_positives = parseJsonField(materialData.positive_attributes);

                        const renderList = (title, items, icon, color) => {
                            if (!items) return '';
                            return `<h6><i class="fas ${icon} text-${color} me-2"></i>${title}</h6>
                            <ul class="list-unstyled small ps-3">
                                ${items.map(item => `<li>- ${escapeHtml(item)}</li>`).join('')}
                            </ul>`;
                        };

                        const htmlContent = `
                    <div class="text-start">
                        ${renderList('社會風險 (S)', s_risks, 'fa-users', 'warning')}
                        ${renderList('相關認證 (S)', s_certs, 'fa-certificate', 'success')}
                        <hr class="my-2">
                        ${renderList('治理風險 (G)', g_risks, 'fa-landmark', 'secondary')}
                        ${renderList('正面實踐 (G)', g_positives, 'fa-check-circle', 'info')}
                    </div>
                `;

                        // 使用 SweetAlert2 彈出詳細資訊
                        Swal.fire({
                            title: `<strong>${escapeHtml(materialName)}</strong><br><small>風險因子細節</small>`,
                            html: htmlContent,
                            showCloseButton: true,
                            showConfirmButton: false,
                        });
                    }
                }
            });
        }

        /**
         * 【V9.5 Bug 修正版】渲染「分析儀」桑基圖，修正了風險流 AI 洞察的文字錯誤。
         */
        function renderSankeyChart(data, mode = 'mass') {
            // 根據 mode 動態決定要操作的 Canvas ID
            const canvasId = `sankeyChart${mode.charAt(0).toUpperCase() + mode.slice(1)}`;
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            // 保持面板開關狀態的邏輯
            const isPanelClosed = $('.sankey-detail-panel').hasClass('is-closed');
            if (isPanelClosed) {
                $('#sankey-show-detail-btn').show();
            } else {
                $('#sankey-show-detail-btn').hide();
            }

            const totalWeight = data.inputs.totalWeight;
            if (totalWeight <= 0) {
                $(ctx.canvas).closest('.sankey-chart-main').html('<div class="d-flex align-items-center justify-content-center h-100 text-muted">無物料數據可繪製圖表。</div>');
                $('.sankey-kpi-bar').html('');
                $('#sankey-detail-content').html('<p class="text-muted">無可用數據。</p>');
                return;
            }

            // --- 1. 更新頂部 KPI 橫幅 (邏輯不變) ---
            const kpiBar = $('.sankey-kpi-bar');
            let kpiHtml = '';
            const createKpiItem = (label, value, unit) => `<div class="kpi-item"><div class="kpi-label">${label}</div><div class="kpi-value">${value}<small class="fs-6 text-muted ms-1">${unit}</small></div></div>`;

            if (mode === 'mass') {
                const recycled_pct = (data.charts.content_by_type.recycled / totalWeight * 100);
                kpiHtml = createKpiItem('總重量', totalWeight.toFixed(2), 'kg') + createKpiItem('再生料比例', recycled_pct.toFixed(1), '%');
            } else if (mode === 'carbon') {
                const co2_reduction_pct = data.virgin_impact.co2 > 1e-9 ? ((data.virgin_impact.co2 - data.impact.co2) / data.virgin_impact.co2 * 100) : 0;
                kpiHtml = createKpiItem('總碳足跡', data.impact.co2.toFixed(2), 'kg CO₂e') + createKpiItem('較原生料減碳', co2_reduction_pct.toFixed(1), '%');
            } else if (mode === 'cost') {
                const cost_reduction_pct = data.virgin_impact.cost > 1e-9 ? ((data.virgin_impact.cost - data.impact.cost) / data.virgin_impact.cost * 100) : 0;
                kpiHtml = createKpiItem('總材料成本', data.impact.cost.toFixed(2), '元') + createKpiItem('較原生料成本', cost_reduction_pct.toFixed(1), '%');
            } else if (mode === 'risk') {
                const avg_s_risk = data.social_impact.overall_risk_score;
                const avg_g_risk = data.governance_impact.overall_risk_score;
                kpiHtml = createKpiItem('社會(S)風險', avg_s_risk.toFixed(1), '/100') + createKpiItem('治理(G)風險', avg_g_risk.toFixed(1), '/100');
            } else if (mode === 'water') {
                const water_reduction_pct = data.virgin_impact.water > 1e-9 ? ((data.virgin_impact.water - data.impact.water) / data.virgin_impact.water * 100) : 0;
                kpiHtml = createKpiItem('總水足跡', data.impact.water.toFixed(2), 'L') + createKpiItem('較原生料節水', water_reduction_pct.toFixed(1), '%');
            }
            kpiBar.html(kpiHtml);

            // --- 2. 準備圖表數據與 AI 敘事 ---
            const theme = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];
            let chartData = { datasets: [{ data: [] }] };
            let unit = '';

            const generateSankeyNarrative = (data, mode) => {
                let title = '分析中...', insight = '', strategy = '', advice = '';

                // --- ▼▼▼ 【BUG 修正】修正 risk 模式的 AI 洞察文字 ▼▼▼ ---
                if (mode === 'risk') {
                    const riskFlows = data.charts.composition.map(c => {
                        const s_risk = data.social_impact.risk_contribution.find(r => r.name === c.name)?.risk_score || 50;
                        const g_risk = data.governance_impact.risk_contribution.find(r => r.name === c.name)?.risk_score || 30;
                        return { name: c.name, risk_flow: c.weight * ((s_risk + g_risk) / 2) };
                    });
                    const totalRiskFlow = riskFlows.reduce((sum, item) => sum + item.risk_flow, 0);
                    if (totalRiskFlow > 0) {
                        const topRiskSource = riskFlows.sort((a,b) => b.risk_flow - a.risk_flow)[0];
                        title = '風險流分析 (供應鏈韌性)';
                        // 修正了此處的變數名稱 topSpender -> topRiskSource
                        insight = `數據顯示 <b>「${escapeHtml(topRiskSource.name)}」</b> 為您的產品帶來了不成比例的供應鏈風險，貢獻了總風險流量的 <b>${((topRiskSource.risk_flow / totalRiskFlow)*100).toFixed(0)}%</b>。`;
                        strategy = '此創新視圖將 ESG 風險量化並視覺化為「風險流」。它能幫助您識別出那些在傳統重量或成本分析中可能被忽略的「高風險、輕重量」的隱形炸彈。';
                        // 修正了此處的變數名稱
                        advice = `請立即將<b>「${escapeHtml(topRiskSource.name)}」</b>列為供應商盡職調查的最高優先級對象，並評估尋找來自低風險地區替代供應商的必要性。`;
                    } else {
                        title = '風險流分析 (供應鏈韌性)'; insight = '目前產品未發現顯著的S&G風險流。'; strategy = '這代表您產品的物料組成在社會與治理風險上表現穩健。'; advice = '請持續監控供應商的S&G表現，以維持此低風險狀態。';
                    }
                    // --- ▲▲▲ 修正完畢 ▲▲▲ ---
                } else if (mode === 'mass') {
                    const recycled_pct = (data.charts.content_by_type.recycled / data.inputs.totalWeight * 100);
                    const eol_recycle_pct = data.inputs.eol_scenario.recycle;
                    title = '物質流分析 (循環經濟)';
                    insight = `此產品的再生料總佔比為 <b>${recycled_pct.toFixed(1)}%</b>，生命終端回收率目標為 <b>${eol_recycle_pct}%</b>。`;
                    strategy = '此圖揭示了產品的「循環度」。核心策略應是最大化「閉環流動」，降低對原生料的依賴，是提升供應鏈穩定性、抵禦原料價格波動風險的關鍵。';
                    advice = '若「原生料」流束過寬，請立即尋找高品質的再生料供應商。若「掩埋/焚化」流束過寬，請重新檢視產品的「可回收性設計」。點擊任一節點可查看詳細資訊並篩選下方地圖。';
                } else if (mode === 'carbon') {
                    const top_emitter = [...data.charts.composition].sort((a,b) => b.co2 - a.co2)[0];
                    title = '碳流分析 (氣候衝擊)';
                    insight = `此產品的總碳足跡為 <b>${data.impact.co2.toFixed(2)} kg CO₂e</b>。其中，<b>「${escapeHtml(top_emitter.name)}」</b>是最大的碳排貢獻者。`;
                    strategy = '此圖揭示了產品減碳的「槓桿點」。識別出的「關鍵碳排熱點」既是最大風險，也是最高效的減碳機會點。';
                    advice = '請將所有資源聚焦於圖中<b class="text-danger">最寬的碳排流束</b>。立即針對該熱點啟動：1) 導入再生料可行性評估。2) 尋找低碳替代材料。3) 探討輕量化設計。';
                } else if (mode === 'cost') {
                    const totalCost = data.impact.cost;
                    if (totalCost <= 0) {
                        return buildNarrativeBlock('成本流分析', '缺少成本數據，無法進行分析。', '請在左側面板為每個組件輸入「單位成本」。', '輸入成本數據後，此處將揭示您的產品成本結構與潛在的優化機會。');
                    }
                    const topSpender = [...data.charts.composition].sort((a,b) => (b.cost || 0) - (a.cost || 0))[0];
                    title = '成本流分析 (財務績效)';
                    insight = `產品總材料成本為 <b>${totalCost.toFixed(2)} 元</b>。其中，<b>「${escapeHtml(topSpender.name)}」</b>是最大的成本動因，貢獻了總成本的 <b>${((topSpender.cost/totalCost)*100).toFixed(0)}%</b>。`;
                    strategy = '此圖將抽象的成本結構轉化為直觀的資金流動，清晰地揭示了您的「財務熱點」，是所有價值工程與成本優化策略的起點。';
                    advice = '您的首要任務是聚焦於圖中<b class="text-danger">最寬的資金流束</b>。立即針對<b>「${escapeHtml(topSpender.name)}」</b>啟動供應商重新議價或尋找替代方案的專案。';
                } else if (mode === 'water') {
                    const waterFlows = Object.entries(data.charts.impact_by_material).map(([name, values]) => ({ name, water_flow: values.water }));
                    const totalWaterFlow = waterFlows.reduce((sum, item) => sum + item.water_flow, 0);
                    if (totalWaterFlow > 0) {
                        const topWaterUser = waterFlows.sort((a,b) => b.water_flow - a.water_flow)[0];
                        title = '水足跡流分析 (水資源風險)';
                        insight = `產品總水足跡為 <b>${totalWaterFlow.toFixed(2)} L</b>。其中 <b>「${escapeHtml(topWaterUser.name)}」</b> 是水資源消耗的絕對熱点，佔總耗水量的 <b>${((topWaterUser.water_flow/totalWaterFlow)*100).toFixed(0)}%</b>。`;
                        strategy = '在全球水資源日益緊張的背景下，水足跡是衡量企業營運風險與社會責任的關鍵指標。此圖幫助您識別供應鏈中的「高水風險」環節。';
                        advice = `若您的供應鏈位於缺水地區，請立即針對<b>「${escapeHtml(topWaterUser.name)}」</b>啟動節水專案，或尋找製程需水量較低的替代材料，以強化供應鏈的水資源韌性。`;
                    } else {
                        title = '水足跡流分析 (水資源風險)'; insight = '目前產品的總水足跡非常低，未發現顯著的耗水熱點。'; strategy = '這代表您產品的物料組成在水資源消耗上表現優異。'; advice = '請將此低水足跡特性作為您產品的永續溝通亮點之一。';
                    }
                }
                return buildNarrativeBlock(title, insight, strategy, advice);
            };

            if (mode === 'mass') {
                unit = 'kg';
                const { recycled, virgin } = data.charts.content_by_type;
                const { recycle, incinerate, landfill } = data.inputs.eol_scenario;
                const recycledOutput = totalWeight * (recycle / 100);
                const incineratedOutput = totalWeight * (incinerate / 100);
                const landfillOutput = totalWeight * (landfill / 100);
                chartData.datasets[0] = {
                    data: [
                        { from: '原生料', to: '產品', flow: virgin }, { from: '再生料', to: '產品', flow: recycled },
                        { from: '產品', to: '回收 (EOL)', flow: recycledOutput }, { from: '產品', to: '焚化 (EOL)', flow: incineratedOutput },
                        { from: '產品', to: '掩埋 (EOL)', flow: landfillOutput }
                    ],
                    colorFrom: (c) => (c.dataset.data[c.dataIndex].from === '原生料' ? '#6c757d' : theme.chartColors[0]),
                    colorTo: (c) => ({ '回收 (EOL)': theme.chartColors[0], '焚化 (EOL)': theme.chartColors[4], '掩埋 (EOL)': '#dc3545' }[c.dataset.data[c.dataIndex].to] || '#adb5bd'),
                    labels: { '原生料': `原生料\n${virgin.toFixed(2)} ${unit}`, '再生料': `再生料\n${recycled.toFixed(2)} ${unit}`, '產品': `產品總重\n${totalWeight.toFixed(2)} ${unit}`, '回收 (EOL)': `回收\n${recycledOutput.toFixed(2)} ${unit}`, '焚化 (EOL)': `焚化\n${incineratedOutput.toFixed(2)} ${unit}`, '掩埋 (EOL)': `掩埋\n${landfillOutput.toFixed(2)} ${unit}` }
                };
            } else if (mode === 'carbon') {
                unit = 'kg CO₂e';
                const { production, eol } = data.charts.lifecycle_co2;
                const totalCo2 = data.impact.co2;
                const sorted = [...data.charts.composition].sort((a, b) => Math.abs(b.co2) - Math.abs(a.co2));
                const top = sorted.slice(0, 4);
                const otherCo2 = sorted.slice(4).reduce((s, c) => s + c.co2, 0);
                let flows = top.map(c => ({ from: c.name, to: '總生產碳排', flow: c.co2 }));
                if (otherCo2 !== 0) flows.push({ from: '其他物料', to: '總生產碳排', flow: otherCo2 });
                flows.push({ from: '總生產碳排', to: '產品總碳足跡', flow: production });
                flows.push({ from: '廢棄處理衝擊', to: '產品總碳足跡', flow: eol });
                chartData.datasets[0] = { data: flows, color: 'rgba(108, 117, 125, 0.6)', labels: { '總生產碳排': `總生產碳排\n${production.toFixed(2)} ${unit}`, '廢棄處理衝擊': `廢棄處理衝擊\n${eol.toFixed(2)} ${unit}`, '產品總碳足跡': `產品總碳足跡\n${totalCo2.toFixed(2)} ${unit}` } };
                top.forEach(c => { chartData.datasets[0].labels[c.name] = `${c.name}\n${c.co2.toFixed(2)} ${unit}`; });
                if (otherCo2 !== 0) chartData.datasets[0].labels['其他物料'] = `其他物料\n${otherCo2.toFixed(2)} ${unit}`;
            } else {
                let flows, totalFlowValue, toNodeLabel;
                if (mode === 'cost') {
                    unit = '元'; totalFlowValue = data.impact.cost; toNodeLabel = '產品總成本';
                    flows = data.charts.composition.map(c => ({ from: c.name, to: toNodeLabel, flow: c.cost || 0 }));
                } else if (mode === 'risk') {
                    unit = '風險單位'; toNodeLabel = '產品總風險';
                    flows = data.charts.composition.map(c => {
                        const s_risk = data.social_impact.risk_contribution.find(r => r.name === c.name)?.risk_score || 50;
                        const g_risk = data.governance_impact.risk_contribution.find(r => r.name === c.name)?.risk_score || 30;
                        return { from: c.name, to: toNodeLabel, flow: c.weight * ((s_risk + g_risk) / 2) };
                    });
                    totalFlowValue = flows.reduce((s, item) => s + item.flow, 0);
                } else { // water
                    unit = 'L'; toNodeLabel = '產品總耗水';
                    flows = Object.entries(data.charts.impact_by_material).map(([name, values]) => ({ from: name, to: toNodeLabel, flow: values.water }));
                    totalFlowValue = flows.reduce((s, item) => s + item.flow, 0);
                }
                if (totalFlowValue <= 0) {
                    $(ctx.canvas).closest('.sankey-chart-main').html(`<div class="d-flex align-items-center justify-content-center h-100 text-muted">無${mode}數據可繪製圖表。</div>`);
                    $('#sankey-detail-title').text('AI 智慧洞察');
                    $('#sankey-detail-content').html(generateSankeyNarrative(data, mode));
                    $('.sankey-detail-panel').addClass('is-open');
                    return;
                }
                chartData.datasets[0] = { data: flows, colorFrom: (c) => theme.chartColors[c.dataIndex % theme.chartColors.length], colorTo: (c) => theme.chartColors[0], labels: {} };
                flows.forEach(f => { chartData.datasets[0].labels[f.from] = `${f.from}\n${f.flow.toFixed(2)} ${unit}`; });
                chartData.datasets[0].labels[toNodeLabel] = `${toNodeLabel}\n${totalFlowValue.toFixed(2)} ${unit}`;
            }

            // --- 3. 渲染圖表，並加入全新的 onClick 事件來控制詳情面板 ---
            charts[canvasId] = new Chart(ctx, {
                type: 'sankey',
                data: chartData,
                options: {
                    responsive: true, maintainAspectRatio: false,
                    onClick: (event, elements) => {
                        if (elements.length === 0) {
                            // 如果點擊圖表空白處，則顯示預設的 AI 總體洞察
                            $('#sankey-detail-title').text('AI 智慧洞察');
                            $('#sankey-detail-content').html(generateSankeyNarrative(data, mode));
                            $('.sankey-detail-panel').addClass('is-open');
                            $('#sankey-show-detail-btn').hide();
                            return;
                        }

                        const clickedElement = elements[0].element;
                        const { from, to, flow } = clickedElement.$context.raw;
                        const clickedLabel = from;

                        $('#sankey-detail-title').text(`節點分析: ${escapeHtml(clickedLabel)}`);
                        let detailContent = `<p>此節點貢獻了 <b>${flow.toFixed(3)} ${unit}</b> 的流量。</p>`;

                        const material = data.charts.composition.find(c => c.name === clickedLabel);
                        if(material) {
                            detailContent += `<h6>關聯數據：</h6>
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item">重量: ${material.weight.toFixed(3)} kg</li>
                        <li class="list-group-item">成本: ${(material.cost || 0).toFixed(2)} 元</li>
                        <li class="list-group-item">碳排: ${material.co2.toFixed(3)} kg CO₂e</li>
                        <li class="list-group-item">再生比例: ${material.percentage.toFixed(1)}%</li>
                    </ul>`;
                        }
                        $('#sankey-detail-content').html(detailContent);
                        $('.sankey-detail-panel').addClass('is-open');
                        $('#sankey-show-detail-btn').hide();
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const flow = context.raw;
                                    return `${flow.from} -> ${flow.to}: ${flow.flow.toFixed(3)} ${unit}`;
                                }
                            }
                        }
                    }
                }
            });

            // --- 4. 將 AI 總體洞察作為詳情面板的預設內容並顯示 ---
            $('#sankey-detail-title').text('AI 智慧洞察');
            $('#sankey-detail-content').html(generateSankeyNarrative(data, mode));
            $('.sankey-detail-panel').removeClass('is-closed');
            $('#sankey-show-detail-btn').hide();
        }

        // 當點擊新的頁籤按鈕時，重新渲染對應的桑基圖
        $(document).on('shown.bs.tab', 'button[data-bs-toggle="tab"]', function (event) {
            const targetId = $(event.target).attr('id');
            if (targetId && targetId.includes('-flow-tab')) {
                let mode = 'mass'; // 預設
                if (targetId.includes('carbon')) mode = 'carbon';
                else if (targetId.includes('cost')) mode = 'cost';
                else if (targetId.includes('risk')) mode = 'risk';
                else if (targetId.includes('water')) mode = 'water';

                if (perUnitData) {
                    renderSankeyChart(perUnitData, mode);
                }
            }
        });

        // 監聽桑基圖詳情面板的「關閉」按鈕
        $(document).on('click', '#sankey-detail-close-btn', function() {
            $('.sankey-detail-panel').addClass('is-closed');
            $('#sankey-show-detail-btn').show();
        });

// 監聽桑基圖詳情面板的「顯示洞察」按鈕
        $(document).on('click', '#sankey-show-detail-btn', function() {
            $('.sankey-detail-panel').removeClass('is-closed');
            $(this).hide();
        });

        // --- 動態模擬器引擎 ---

        /**
         * 防抖動 (Debounce) 函式
         * @description 防止事件被過於頻繁地觸發，確保只有在使用者停止操作後才執行。
         * @param {Function} func - 要執行的函式
         * @param {number} delay - 延遲時間 (毫秒)
         */
        function debounce(func, delay) {
            let timeout;
            return function(...args) {
                const context = this;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), delay);
            };
        }

        /**
         * 處理桑基圖模擬器變動的核心函式
         */
        function handleSankeySimulation() {
            if (!perUnitData) return;

            const sliderValue = parseInt($('#sankey-simulator-slider').val());
            $('#sankey-simulator-value').text(`${sliderValue}%`);

            // 顯示全螢幕讀取遮罩
            showLoading(true, `模擬 ${sliderValue}% 再生料...`);

            // 1. 建立一個臨時的、被修改過的 BOM
            const tempComponents = perUnitData.inputs.components.map(c => ({
                ...c,
                percentage: sliderValue // 將所有物料的再生比例設為滑桿的值
            }));

            // 2. 建立一個與主計算流程完全相同的 payload
            const payload = {
                components: tempComponents,
                versionName: `${$('#versionName').val() || '新分析'} (模擬 ${sliderValue}% 再生料)`,
                inputs: { ...perUnitData.inputs }, // 複製其他輸入
                eol: perUnitData.inputs.eol_scenario
            };

            // 3. 發送到後端進行一次完整的重新計算
            $.ajax({
                url: '', // 提交到當前頁面，觸發後端計算
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        // 4. 成功後，用新的數據更新全局變數並重繪整個儀表板
                        perUnitData = data;
                        // 為了讓使用者知道這是模擬結果，動態更新報告標題
                        perUnitData.versionName = payload.versionName;
                        updateDashboard();
                    } else {
                        alert('模擬計算失敗：' + data.error);
                    }
                },
                error: () => alert('模擬時發生伺服器錯誤。'),
                complete: () => showLoading(false) // 計算完成後隱藏遮罩
            });
        }

// 4. 為滑桿綁定事件監聽器，並套用 debounce
        $(document).on('input', '#sankey-simulator-slider', debounce(handleSankeySimulation, 500));



        // 【S面向強化】新增的程式碼片段 (1/2)

        /**
         * 【V2.1 - 最終修正版】永續故事原型判斷引擎
         * @description 使用更嚴謹、基於數據特徵且具備錯誤處理的邏輯來判斷產品的溝通原型。
         * @param {object} data - 完整的 perUnitData 物件
         * @returns {object} - 包含 name, icon, color, description 的原型物件
         */
        function generateStoryProfile(data) {
            // 【健壯性強化】使用可選串聯 (?.) 確保即使部分數據缺失也不會導致程式錯誤
            const story_score = data?.story_score;
            const environmental_fingerprint_scores = data?.environmental_fingerprint_scores;
            const impact = data?.impact;
            const virgin_impact = data?.virgin_impact;
            const inputs = data?.inputs;
            const charts = data?.charts;
            const environmental_performance = data?.environmental_performance;

            // 如果核心數據不存在，返回一個安全的預設值
            if (!story_score || !environmental_fingerprint_scores || !impact || !virgin_impact || !inputs || !charts || !environmental_performance) {
                return { name: '數據不完整', icon: 'fa-question-circle', color: 'secondary', description: '缺少核心數據，無法進行故事原型分析。' };
            }

            const co2_val = impact.co2;
            const co2_reduction_pct = environmental_fingerprint_scores.co2;
            const recycled_pct = (inputs.totalWeight > 0) ? (charts.content_by_type.recycled / inputs.totalWeight * 100) : 0;
            const has_trade_off = story_score.weaknesses.some(w => w.includes('衝擊轉移'));
            const is_pseudo_ecological = story_score.weaknesses.some(w => w.includes('偽生態設計'));

            // 【第一層：風險與警告優先】
            if (is_pseudo_ecological) {
                return { name: '矛盾者 (The Contradictor)', icon: 'fa-exclamation-triangle', color: 'danger',
                    description: '產品的永續故事存在內在矛盾：投入了大量循環材料，卻導致了更高的碳排放。在解決此核心問題前，不建議進行大規模的永續溝通。' };
            }
            if (has_trade_off) {
                return { name: '權衡者 (The Trader)', icon: 'fa-balance-scale-left', color: 'warning',
                    description: '產品為達成核心減碳目標，在其他環境面向做出了取捨。溝通時應採取透明策略，坦誠地說明這是一個經過深思熟慮的「權衡」決策，而非完美的解決方案。' };
            }

            // 【第二層：卓越表現】
            if (co2_val < -0.001) {
                return { name: '氣候英雄 (The Climate Hero)', icon: 'fa-star', color: 'success',
                    description: '產品實現了「碳負排放」的卓越成就，是應對氣候變遷的終極解決方案。您的故事核心是關於「從大氣中移除碳」，而不僅僅是減少排放。' };
            }
            // 【健壯性強化】使用 ?.
            if ((environmental_performance?.breakdown?.nature > 85) || (environmental_performance?.breakdown?.water > 85)) {
                return { name: '守護者 (The Guardian)', icon: 'fa-seedling', color: 'success',
                    description: '您的產品在保護「自然資本」（生物多樣性、水資源）方面表現傑出。您的故事應圍繞著「與自然和諧共生」，能與關心生態的消費者產生強烈共鳴。' };
            }
            if (co2_reduction_pct > 65 || recycled_pct > 75) {
                return { name: '創新者 (The Innovator)', icon: 'fa-lightbulb', color: 'primary',
                    description: '您的產品透過卓越的材料科學或循環設計，達成了行業領先的環境效益。您的故事是關於「突破」與「遠見」，向市場展示了永續發展的全新可能性。' };
            }

            // 【第三層：穩健與平衡】
            // 【健壯性強化】使用 ?. 並提供預設空陣列 []
            const all_e_scores = Object.values(environmental_performance?.breakdown || {});
            if (all_e_scores.length > 0) {
                const avgScore = all_e_scores.reduce((a, b) => a + b, 0) / all_e_scores.length;
                const stdDev = Math.sqrt(all_e_scores.map(x => Math.pow(x - avgScore, 2)).reduce((a, b) => a + b, 0) / all_e_scores.length);
                if (avgScore > 60 && stdDev < 15) {
                    return { name: '智者 (The Sage)', icon: 'fa-user-graduate', color: 'info',
                        description: '您的產品在各項指標中取得了良好平衡，是一個經過深思熟慮、數據驅動的穩健設計。您的故事應強調「全面性」與「可靠性」，向市場傳達專業與可信的形象。' };
                }
            }

            // 【第四層：預設與潛力股】
            return { name: '潛力股 (The Potential)', icon: 'fa-search', color: 'secondary',
                description: '目前的設計是一個堅實的起點，雖然尚未在特定領域達到頂尖，但已展現出明確的改善潛力。您的故事是關於「持續進步」與「透明的旅程」。' };
        }

        /**
         * 【V9.9 溝通策略中心版 - 最終強化版】
         * @description 整合了全新的「AI 推薦溝通 SDG」與「綠色漂洗風險儀表板」。
         */
        function renderStorytellingHub(data) {
            const storyData = data.story_score;
            const { score, rating } = storyData;
            const archetype = generateStoryProfile(data); // 故事原型邏輯保持不變

            // 【核心升級】準備新的 SDG / 風險儀表板 HTML
            const storySdgsData = data.story_sdgs;
            let sdgOrRiskHtml = '';
            if (storySdgsData && storySdgsData.risk) {
                // 情境B：渲染「綠色漂洗風險儀表板」
                sdgOrRiskHtml = `
            <h6 class="mb-2">綠色漂洗風險儀表板</h6>
            <div class="p-3 rounded-3 bg-danger-subtle border border-danger-subtle">
                <h5><i class="fas fa-exclamation-triangle text-danger me-2"></i>${escapeHtml(storySdgsData.risk)}</h5>
                <p class="small text-danger-emphasis mb-0">${escapeHtml(storySdgsData.message)}</p>
            </div>`;
            } else if (storySdgsData && storySdgsData.recommendations) {
                // 情境A：渲染「AI 推薦溝通 SDG」
                const sdgIconsHtml = storySdgsData.recommendations.map(sdg => {
                    const num_str = String(sdg.number).padStart(2, '0');
                    return `<img src="assets/img/SDGs_${num_str}.png" class="sdg-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="${escapeHtml(sdg.reason)}">`;
                }).join('');
                sdgOrRiskHtml = `
            <h6 class="mb-2">AI 推薦溝通 SDG</h6>
            <div class="p-3 rounded-3 bg-light-subtle">
                <div class="sdg-icons-container">${sdgIconsHtml}</div>
                <p class="small text-muted mb-0 mt-2">將滑鼠懸停在圖示上以查看推薦理由。</p>
            </div>`;
            }

            // --- 準備「條件式」的溝通指南與證據內容 ---
            const audiences = {
                investor: { title: '對投資人/金融機構', points: [] },
                consumer: { title: '對終端消費者 (B2C)', points: [] },
                b2b_client: { title: '對企業客戶 (B2B)', points: [] }
            };
            const co2_reduction_pct = data.environmental_fingerprint_scores.co2;
            const recycled_pct = (data.inputs.totalWeight > 0) ? (data.charts.content_by_type.recycled / data.inputs.totalWeight * 100) : 0;
            const has_green_discount = data.commercial_benefits?.success && data.commercial_benefits.green_premium_per_unit < 0;

            // 只有在 ESG 分數高於 60 時，才建議對投資人溝通
            if (data.esg_scores.combined_score > 60) {
                audiences.investor.points.push(`ESG 總分高達 <b>${data.esg_scores.combined_score}</b>，展現低風險與高管理水平。`);
                audiences.investor.points.push(`供應鏈 S&G 風險分數為 <b>${((data.social_impact.overall_risk_score + data.governance_impact.overall_risk_score)/2).toFixed(1)}</b>，符合盡職調查要求。`);
            }
            if (has_green_discount) {
                audiences.investor.points.push(`永續設計帶來了 <b>${Math.abs(data.commercial_benefits.green_premium_per_unit).toFixed(2)} 元/件</b> 的成本節省，提升毛利。`);
            }

            // 只有在再生比例 > 10% 時，才建議對消費者溝通這點
            if (recycled_pct > 10) {
                audiences.consumer.points.push(`採用 <b>${recycled_pct.toFixed(0)}%</b> 的再生材料，將廢棄物變為珍寶。`);
            }
            // 只有在實際有減碳時，才溝通減碳效益
            const co2_saved = data.virgin_impact.co2 - data.impact.co2;
            if (co2_saved > 0) {
                audiences.consumer.points.push(`每一個產品，都相當於為地球減少了 <b>${co2_saved.toFixed(2)} kg</b> 的碳排放。`);
                audiences.consumer.points.push(`相當於減少 <b>${data.equivalents.car_km.toFixed(1)} 公里</b> 的汽車里程。`);
            }
            // 只有社會風險低於 50 (中等風險) 時，才建議溝通這點
            if (data.social_impact.overall_risk_score < 50) {
                audiences.consumer.points.push(`我們關心供應鏈夥伴，社會風險分數遠優於行業平均水平。`);
            }

            // B2B 客戶的溝通點
            audiences.b2b_client.points.push(`產品碳足跡為 <b>${data.impact.co2.toFixed(2)} kg CO₂e</b>，可直接用於計算您的 Scope 3 排放。`);
            if (data.circularity_analysis.mci_score > 40) {
                audiences.b2b_client.points.push(`高循環性 (MCI: <b>${data.circularity_analysis.mci_score}</b>)，能幫助您達成自身的循環經濟目標。`);
            }
            if (data.governance_impact.overall_risk_score < 50) {
                audiences.b2b_client.points.push(`低 S&G 風險，代表我們是一個穩定、可靠、負責任的永續供應鏈夥伴。`);
            }

            // 「數據到聲明」證據產生器
            const claims = [];
            if (co2_reduction_pct > 1) {
                claims.push({ claim: `碳足跡較產業基準降低 <b>${co2_reduction_pct.toFixed(1)}%</b>`, evidence: `原生料基準碳排: ${data.virgin_impact.co2.toFixed(2)}, 本產品碳排: ${data.impact.co2.toFixed(2)} kg CO₂e` });
            }
            if (recycled_pct > 1) {
                claims.push({ claim: `採用 <b>${recycled_pct.toFixed(1)}%</b> 再生材料`, evidence: `總重: ${data.inputs.totalWeight.toFixed(2)}kg, 再生料重: ${data.charts.content_by_type.recycled.toFixed(2)}kg` });
            }
            if (data.environmental_fingerprint_scores.water > 1) {
                claims.push({ claim: `節省 <b>${data.environmental_fingerprint_scores.water.toFixed(1)}%</b> 的水資源消耗`, evidence: `相當於每個產品節省 ${data.equivalents.showers.toFixed(1)} 次淋浴用水` });
            }
            if (has_green_discount) {
                claims.push({ claim: `實現每件 <b>${Math.abs(data.commercial_benefits.green_premium_per_unit).toFixed(2)} 元</b> 的綠色折扣`, evidence: `原生料成本: ${data.virgin_impact.cost.toFixed(2)} 元, 本產品成本: ${data.impact.cost.toFixed(2)} 元` });
            }

            // --- 產生 HTML ---
            let audienceHtml = '';
            for(const key in audiences) {
                const aud = audiences[key];
                if (aud.points.length === 0) {
                    audienceHtml += `<div class="col-md-4"><h6 class="small fw-bold text-primary">${aud.title}</h6><div class="small text-muted p-2 bg-light-subtle rounded">目前數據尚無針對此受眾的顯著溝通亮點。</div></div>`;
                    continue;
                }
                audienceHtml += `<div class="col-md-4"><h6 class="small fw-bold text-primary">${aud.title}</h6><ul class="list-unstyled small">`;
                aud.points.forEach(p => { audienceHtml += `<li><i class="fas fa-check-circle text-success fa-xs me-2"></i>${p}</li>`; });
                audienceHtml += `</ul></div>`;
            }

            const claimsHtml = claims.length > 0 ? claims.map(c => `
        <div class="col-md-6 mb-2">
            <div class="p-2 border rounded-3">
                <p class="mb-1">📢 <b>可宣告：</b>${c.claim}</p>
                <small class="text-muted d-block"><b>證據：</b>${c.evidence}</small>
            </div>
        </div>`).join('') : '<div class="col-12"><div class="alert alert-warning small">目前數據尚不足以產生強而有力的、可供驗證的永續聲明。</div></div>';

            const sdgHtml = generateSdgIconsHtml([12]);
            const html = `
    <div class="card h-100 shadow-sm">
        <div class="card-header bg-light-subtle d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center"><i class="fas fa-bullhorn text-primary me-2"></i>永續溝通策略中心<span class="badge bg-primary-subtle text-primary-emphasis ms-2">行銷 (M)</span></h5>
            ${sdgHtml}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="storytelling-hub" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
            <div class="row g-4 align-items-center">
                <div class="col-lg-3 text-center border-end">
                    <h6 class="text-muted">故事力™ 總分</h6>
                    <div class="display-3 fw-bolder text-primary">${score}</div>
                    <div class="badge fs-6 bg-primary-subtle text-primary-emphasis border border-primary-subtle">評級: ${rating}</div>
                </div>
                <div class="col-lg-9">
                    <div class="row">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <h6 class="mb-2">故事原型 (Archetype)</h6>
                            <div class="p-3 rounded-3 bg-light-subtle h-100">
                                <h5><i class="fas ${archetype.icon} text-${archetype.color} me-2"></i>${archetype.name}</h5>
                                <p class="small text-muted mb-0">${archetype.description}</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            ${sdgOrRiskHtml}
                        </div>
                    </div>
                </div>
            </div>
            <hr>
            <ul class="nav nav-tabs nav-tabs-bordered" id="storyHubTab" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" id="audience-tab" data-bs-toggle="tab" data-bs-target="#audience-pane" type="button"><i class="fas fa-users me-2"></i>目標受眾溝通指南</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="claims-tab" data-bs-toggle="tab" data-bs-target="#claims-pane" type="button"><i class="fas fa-clipboard-check me-2"></i>「數據到聲明」證據產生器</button></li>
            </ul>
            <div class="tab-content pt-3" id="storyHubTabContent">
                <div class="tab-pane fade show active" id="audience-pane" role="tabpanel"><div class="row">${audienceHtml}</div></div>
                <div class="tab-pane fade" id="claims-pane" role="tabpanel"><div class="row">${claimsHtml}</div></div>
            </div>
        </div>
        <div class="card-footer text-center"><button class="btn btn-primary" id="generate-comms-content-btn" data-bs-toggle="modal" data-bs-target="#ai-comms-modal"><i class="fas fa-magic me-2"></i>AI 產生溝通文案</button></div>
    </div>`;

            $('#storytelling-hub-container').html(html);
            // 重新初始化 Tooltips
            $('#storytelling-hub-container [data-bs-toggle="tooltip"]').tooltip();
        }

        /**
         * 【v7.3 介面統一版】產生「永續性深度剖析」模組的完整 HTML 結構
         */
        function generateSustainabilityDeepDiveHtml() {
            // 【修改處】新增 SDG 圖示
            const sdgHtml = generateSdgIconsHtml([9, 12, 13]);
            return `
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-search-location text-primary me-2"></i>永續性深度剖析
                <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>
            </h5>
            ${sdgHtml}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="sustainability-deep-dive" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
            <div class="progress mb-3" style="height: 5px;">
                <div id="act-progress-bar" class="progress-bar" role="progressbar" style="width: 33.3%;" aria-valuenow="1" aria-valuemin="1" aria-valuemax="3"></div>
            </div>
            <h6 class="text-center text-muted mb-3" id="act-indicator"></h6>
            <div class="tab-content" id="advancedAnalysisTabContent">
                <div class="tab-pane fade show active" id="pane-macro" role="tabpanel"><div class="row g-4"><div class="col-md-5"><div style="height: 300px;"><canvas id="categoryCombinedChart"></canvas></div></div><div class="col-md-7"><div id="narrative-macro"></div></div></div></div>
                <div class="tab-pane fade" id="pane-positioning" role="tabpanel"><div class="row g-4"><div class="col-md-5"><div style="height: 300px;"><canvas id="impactMatrixChart"></canvas></div></div><div class="col-md-7"><div id="narrative-positioning"></div></div></div></div>
                <div class="tab-pane fade" id="pane-tracing" role="tabpanel"><div class="row g-4"><div class="col-md-5"><div style="height: 300px;"><canvas id="waterfallChart"></canvas></div></div><div class="col-md-7"><div id="narrative-tracing"></div></div></div></div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <button class="btn btn-outline-secondary" id="prev-act-btn" disabled><i class="fas fa-arrow-left me-2"></i>返回上一幕</button>
            <button class="btn btn-primary" id="next-act-btn">繼續分析<i class="fas fa-arrow-right ms-2"></i></button>
        </div>
    </div>`;
        }

        /**
         * 【v7.3 介面統一版】產生「成本效益深度剖析」模組的完整 HTML 結構
         */
        function generateCostBenefitDeepDiveHtml() {
            // 【修改處】新增 SDG 圖示
            const sdgHtml = generateSdgIconsHtml([8, 9, 13]);
            return `
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-dollar-sign text-primary me-2"></i>成本效益深度剖析
                <span class="badge bg-primary-subtle text-primary-emphasis ms-2">綜合 (E+F)</span>
            </h5>${sdgHtml}
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="cost-benefit-deep-dive" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
            <div class="progress mb-3" style="height: 5px;"><div id="cost-act-progress-bar" class="progress-bar" role="progressbar" style="width: 33.3%;"></div></div>
            <h6 class="text-center text-muted mb-3" id="cost-act-indicator"></h6>
            <div class="tab-content" id="costAnalysisTabContent">
                <div class="tab-pane fade show active" id="cost-pane-macro"><div class="row g-4"><div class="col-md-5"><div style="height: 300px;"><canvas id="costCompositionChart"></canvas></div></div><div class="col-md-7"><div id="narrative-cost-macro"></div></div></div></div>
                <div class="tab-pane fade" id="cost-pane-positioning"><div class="row g-4"><div class="col-md-5"><div style="height: 300px;"><canvas id="costCarbonMatrixChart"></canvas></div></div><div class="col-md-7"><div id="narrative-cost-positioning"></div></div></div></div>
                <div class="tab-pane fade" id="cost-pane-comparison"><div class="row g-4"><div class="col-md-5"><div style="height: 300px;"><canvas id="costComparisonChart"></canvas></div></div><div class="col-md-7"><div id="narrative-cost-comparison"></div></div></div></div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <button class="btn btn-outline-secondary" id="prev-cost-act-btn" disabled><i class="fas fa-arrow-left me-2"></i>返回上一幕</button>
            <button class="btn btn-primary" id="next-cost-act-btn">繼續分析<i class="fas fa-arrow-right ms-2"></i></button>
        </div>
    </div>`;
        }

        /**
         * 【v7.3 介面統一版】產生「環境指紋深度剖析」模組的完整 HTML 結構
         */
        function generateResilienceDeepDiveHtml() {
            // 【修改處】新增 SDG 圖示
            const sdgHtml = generateSdgIconsHtml([6, 12, 13, 14, 15]);
            return `
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
             <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-fingerprint text-primary me-2"></i>環境指紋深度剖析
                <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>
            </h5>
            ${sdgHtml}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="resilience-deep-dive" title="這代表什麼？"></i>
        </div>
        <div class="card-body">
            <div class="progress mb-3" style="height: 5px;"><div id="resilience-act-progress-bar" class="progress-bar" role="progressbar" style="width: 33.3%;"></div></div>
            <h6 class="text-center text-muted mb-3" id="resilience-act-indicator"></h6>
            <div class="tab-content" id="resilienceAnalysisTabContent">
                <div class="tab-pane fade show active" id="resilience-pane-1"><div class="row g-4"><div class="col-md-5"><div style="height: 300px;"><canvas id="resilienceFingerprintChart"></canvas></div></div><div class="col-md-7"><div id="narrative-resilience-1"></div></div></div></div>
                <div class="tab-pane fade" id="resilience-pane-2"><div class="row g-4"><div class="col-md-5"><div style="height: 300px;"><canvas id="resilienceStackedHotspotChart"></canvas></div></div><div class="col-md-7"><div id="narrative-resilience-2"></div></div></div></div>
                <div class="tab-pane fade" id="resilience-pane-3"><div class="row g-4"><div class="col-md-5"><div class="chart-container" style="height: 300px;"><canvas id="tradeoffMatrixChart"></canvas></div></div><div class="col-md-7"><div id="narrative-resilience-3"></div></div></div></div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <button class="btn btn-outline-secondary" id="prev-resilience-act-btn" disabled><i class="fas fa-arrow-left me-2"></i>返回上一幕</button>
            <button class="btn btn-primary" id="next-resilience-act-btn">繼續分析<i class="fas fa-arrow-right ms-2"></i></button>
        </div>
    </div>`;
        }

        // START: 完整版 GetInterpretation 函式
        // ===================================================================
        /**
         * 【v7.5 完整解讀版】根據主題，提供彈出視窗中的詳細數據解讀文字
         */
        function getInterpretation(topic, data) {
            if (!data) return;

            let title = '', body = '';
            const imp = data.impact;
            const v_imp = data.virgin_impact;
            const co2_val = imp.co2;
            const co2_reduction_pct = v_imp.co2 > 0.001 ? ((v_imp.co2 - co2_val) / v_imp.co2 * 100) : (v_imp.co2 <= 0 && co2_val < 0 ? 100 : 0);
            const energy_reduction_pct = v_imp.energy > 0 ? ((v_imp.energy - imp.energy) / v_imp.energy * 100) : 0;
            const water_reduction_pct = v_imp.water > 0 ? ((v_imp.water - imp.water) / v_imp.water * 100) : 0;

            const build_html = (def, data_insight, strat_imp, act_rec, formula = '') => {
                let html = '';
                if (def) html += `<div class="interp-section"><h5><i class="fas fa-book-open text-primary fa-fw me-3"></i>定義</h5><p>${def}</p></div>`;
                if (data_insight) html += `<div class="interp-section"><h5><i class="fas fa-search-plus text-info fa-fw me-3"></i>數據洞察</h5><div>${data_insight}</div></div>`;
                if (strat_imp) html += `<div class="interp-section"><h5><i class="fas fa-sitemap text-success fa-fw me-3"></i>策略意涵</h5><p>${strat_imp}</p></div>`;
                if (act_rec) html += `<div class="interp-section"><h5><i class="fas fa-bullseye text-danger fa-fw me-3"></i>行動建議</h5><div>${act_rec}</div></div>`;
                if (formula) html += `<div class="interp-section"><h5><i class="fas fa-calculator text-secondary fa-fw me-3"></i>計算公式</h5><div class="formula">${formula}</div></div>`;
                return html;
            };

            switch (topic) {
                case 'waste-scorecard':
                    title = '解讀：生產廢棄物計分卡';
                    let formula_waste = `改善分數 = ( (原生料總生產廢棄物 - 當前設計總生產廢棄物) / 原生料總生產廢棄物 ) * 100`;
                    let def_waste = `此計分卡評估產品在「搖籃到大門」(Cradle-to-Gate)階段，因原料開採與加工製造所產生的「<span class="highlight-term">生產廢棄物</span>」總量。這與生命週期終端的「廢棄處理」是不同的概念。`;
                    let insight_waste = `儀表板上的各項指標分別代表：
                <ul>
                    <li><strong>改善表現分數：</strong>一個綜合評分(0-100)，分數越高代表您的設計相較於「100%原生料」基準，在生產過程中產生的廢棄物越少。</li>
                    <li><strong>總生產廢棄物 (kg)：</strong>這是產品生命週期前期產生的廢棄物總公斤數。</li>
                    <li><strong>主要貢獻來源：</strong>找出對廢棄物產生貢獻最大的物料組件，它們通常代表著「材料利用率」較低的環節。</li>
                </ul>`;
                    let strat_waste = `此指標是衡量企業「<span class="highlight-term">資源效率</span>」與「<span class="highlight-term">清潔生產</span>」能力的關鍵。降低生產廢棄物不僅能減少後續處理成本與環境衝擊，更直接代表了更高的原料利用率，有助於降低整體生產成本。`;
                    let advice_waste = `若分數偏低或發現顯著熱點，您的行動建議如下：
                <ol>
                    <li><strong>針對熱點物料：</strong>與該物料的供應商進行溝通，深入了解其製程損耗率(scrap rate)，並將其納入供應商評選標準。</li>
                    <li><strong>採用再生材料：</strong>使用再生材料通常能大幅減少前端開採與提煉過程中的廢棄物產生。</li>
                    <li><strong>優化產品設計：</strong>透過優化設計減少加工程序（如沖壓、切削），從源頭降低材料損耗。</li>
                </ol>`;
                    body = build_html(def_waste, insight_waste, strat_waste, advice_waste, formula_waste);
                    break;

                // ▼▼▼【已為以下五張卡片加上公式】▼▼▼
                case 'energy-scorecard':
                    title = '解讀：總能源消耗 (CED) 計分卡';
                    let formula_energy = `改善分數 = ( (原生料總能耗 - 當前設計總能耗) / 原生料總能耗 ) * 100`;
                    body = build_html(
                        '此計分卡評估產品在「搖籃到大門」階段，製造所需投入的總初級能源，也稱為「<span class="highlight-term">隱含能源</span>」(Embodied Energy)，單位為百萬焦耳(MJ)。',
                        '儀表板的「改善分數」越高，代表您的設計相較於100%原生料基準，在節能上的成效越卓越。',
                        '能源消耗與營運成本及地緣政治風險高度相關。降低產品的隱含能源，有助於提升供應鏈的「<span class="highlight-term">穩定性</span>」與「<span class="highlight-term">成本可預測性</span>」。',
                        '若分數偏低，請優先針對「主要貢獻來源」進行優化。選擇<span class="highlight-term">再生材料</span>是降低隱含能源最有效的途徑之一，因其通常能耗遠低於原生材料的開採與提煉。',
                        formula_energy
                    );
                    break;

                case 'acidification-scorecard':
                    title = '解讀：酸化潛力 (AP) 計分卡';
                    let formula_ap = `改善分數 = ( (原生料酸化潛力 - 當前設計酸化潛力) / 原生料酸化潛力 ) * 100`;
                    body = build_html(
                        '此計分卡評估產品生命週期中，會導致「<span class="highlight-term">酸雨</span>」的污染物（如SOx, NOx）的總排放潛力，單位為公斤二氧化硫當量 (kg SO₂e)。',
                        '儀表板的「改善分數」越高，代表您的設計相較於100%原生料基準，對酸化問題的貢獻越低。',
                        '高酸化潛力會對生態系統造成破壞。在許多國家，SOx與NOx的排放受到嚴格的空氣品質法規管制，是企業面臨的「<span class="highlight-term">合規風險</span>」之一。',
                        '若要降低此數值，請優先檢視熱點物料的「<span class="highlight-term">供應鏈能源結構</span>」（鼓勵採用再生能源）與「<span class="highlight-term">運輸方式</span>」（選擇潔淨運輸工具）。',
                        formula_ap
                    );
                    break;

                case 'eutrophication-scorecard':
                    title = '解讀：優養化潛力 (EP) 計分卡';
                    let formula_ep = `改善分數 = ( (原生料優養化潛力 - 當前設計優養化潛力) / 原生料優養化潛力 ) * 100`;
                    body = build_html(
                        '此計分卡評估產品生命週期中，會導致水體「<span class="highlight-term">優養化</span>」（如藻類過度繁殖）的營養鹽（如氮、磷）的總排放潛力，單位為公斤磷酸鹽當量 (kg PO₄e)。',
                        '儀表板的「改善分數」越高，代表您的設計對水體生態的衝擊越低。此衝擊主要與農業活動（肥料流失）及工業廢水排放有關。',
                        '水體優養化是全球性的水污染問題。高優養化潛力的產品，可能在供應鏈的「<span class="highlight-term">永續性稽核</span>」中被視為高風險。',
                        '若分數偏低，請優先檢視熱點物料的來源。若為<span class="highlight-term">生物基原料</span>，應選擇來自永續農業的供應商；若為<span class="highlight-term">工業材料</span>，應確保其工廠有完善的廢水處理設施。',
                        formula_ep
                    );
                    break;

                case 'ozone-depletion-scorecard':
                    title = '解讀：臭氧層破壞 (ODP) 計分卡';
                    let formula_odp = `改善分數 = ( (原生料ODP - 當前設計ODP) / 原生料ODP ) * 100`;
                    body = build_html(
                        '此計分卡評估產品生命週期中，所排放的破壞平流層臭氧的化學物質（如CFCs）的總潛力，單位為公斤CFC-11當量 (kg CFC-11e)。',
                        '在現代產品中，此數值通常很低。任何顯著的衝擊都可能代表嚴重的合規風險。',
                        '臭氧層破壞是一個受到《<span class="highlight-term">蒙特婁議定書</span>》嚴格國際公約管制的議題。此指標更偏向「<span class="highlight-term">風險控管</span>」而非「績效優化」。',
                        '建議進行「<span class="highlight-term">盡職調查</span>」，確保供應鏈中（特別是發泡材料、冷凍設備）沒有使用任何已被淘汰的ODP物質。',
                        formula_odp
                    );
                    break;

                case 'pocp-scorecard':
                    title = '解讀：光化學煙霧 (POCP) 計分卡';
                    let formula_pocp = `改善分數 = ( (原生料POCP - 當前設計POCP) / 原生料POCP ) * 100`;
                    body = build_html(
                        '此計分卡評估產品生命週期中，所排放的揮發性有機化合物(VOCs)等物質，在陽光下形成地面臭氧（即「<span class="highlight-term">光化學煙霧</span>」）的潛力。',
                        '儀表板的「改善分數」越高，代表您的設計對區域空氣品質的負面影響越小。此衝擊主要來自溶劑、塗料、油墨、黏著劑的揮發。',
                        '地面臭氧是影響人體健康的主要空氣污染物之一。許多地區對VOCs的排放有嚴格的工業安全與環保法規。',
                        '若分數偏低，請優先評估熱點物料的製程，改用<span class="highlight-term">低VOC或水性</span>的塗料與黏著劑是最高效的改善策略。',
                        formula_pocp
                    );
                    break;

                case 'financial-risk-summary-dashboard':
                    title = '解讀：綜合財務風險儀表板';
                    // 【核心升級】新增此儀表板的計算公式
                    let formula_fin_risk = `財務風險總分 = (總曝險金額 / 產品材料總成本) × 100
總曝險金額 = (CBAM成本 + 塑膠稅成本 + 自然風險VaR + 綠色材料溢價)`;
                    let def_fin_risk = `此儀表板是您的「<span class="highlight-term">永續財務長(CFO)儀表板</span>」。它的核心目標是將所有可量化的、由ESG因素引發的潛在財務成本整合在一起，提供一個高層次的、可直接用於商業決策的總曝險視圖。`;
                    let insight_fin_risk = `此儀表板的每個部分都在回答一個關鍵的財務問題：
        <ul>
            <li><strong>頂層 KPI 區塊：</strong>這是您的「高階管理層快照」，回答了四個核心問題：
                <ol>
                    <li><b>總曝險金額：</b>我總共面臨多少錢的風險？</li>
                    <li><b>財務風險總分：</b>這個風險相對於我的材料成本有多嚴重？(分數越低越好)</li>
                    <li><b>曝險佔淨利比：</b>最壞的情況下，這些風險會侵蝕掉我多少比例的淨利？</li>
                    <li><b>最大風險來源：</b>我最應該先處理哪個問題？</li>
                </ol>
            </li>
            <li><strong>風險細項分析表：</strong>提供了所有風險的「<span class="highlight-term">原始數據</span>」，詳細列出了每個項目的曝險金額與佔總曝險的百分比。</li>
            <li><strong>曝險金額比較圖：</strong>將分析表中的數據「<span class="highlight-term">視覺化</span>」，讓您能快速地透過長條圖的長度，比較不同風險的絕對衝擊大小。</li>
        </ul>`;
                    let strat_fin_risk = `在策略層面，此儀表板將抽象的「永續風險」轉化為具體的「<span class="highlight-term">財務數字</span>」，是永續部門與財務、採購、營運部門之間最佳的溝通橋樑。它讓企業能將永續性納入全面的風險管理與定價策略中，做出更具韌性的商業決策。`;
                    let advice_fin_risk = `我們建議採用以下決策流程：
        <ol>
            <li>首先，關注「<span class="highlight-term">曝險佔淨利比</span>」這個 KPI。如果此比例過高，代表永續風險已嚴重威脅到您的獲利能力，應立即採取行動。</li>
            <li>接著，從「<span class="highlight-term">最大風險來源</span>」KPI 與右側的圖表中，鎖定造成問題的元兇。</li>
            <li>最後，閱讀底部的「<span class="highlight-term">AI 智慧洞察</span>」，它已經為您針對最大風險來源提供了具體的下一步策略建議。</li>
        </ol>`;
                    body = build_html(def_fin_risk, insight_fin_risk, strat_fin_risk, advice_fin_risk, formula_fin_risk);
                    break;
                case 'storytelling-hub':
                    title = '解讀：永續溝通策略中心';
                    let formula_story = `這是一個基於規則的演算法，而非簡單的數學公式。其概念如下：
故事力分數 = 基礎分 + (減碳成效 × 權重) + (循環敘事 × 權重) + (社會責任 × 權重) - (故事矛盾扣分)`;
                    body = build_html(
                        '此儀表板是您企業的「<span class="highlight-term">永續行銷大腦</span>」，其核心目標是將複雜、抽象的LCA數據，轉化為精準、有力、且無漂綠風險的市場溝通策略與材料。',
                        `此中心包含三個核心模組，構成一個完整的溝通策略流程：
                <ul>
                    <li><b>故事原型 (Archetype)：</b>首先，它為您的產品定義一個核心的「溝通角色」(如英雄、創新者)。這將是您所有行銷故事的情感基調與敘事主軸。</li>
                    <li><b>目標受眾溝通指南：</b>接著，它告訴您這個故事該「對誰說」以及「說什麼」。針對不同利害關係人（投資人、消費者、B2B客戶），提供客製化的溝通重點，讓您的訊息傳遞更精準。</li>
                    <li><b>「數據到聲明」證據產生器：</b>最後，它為您的所有行銷「聲明」(Claim) 提供數據背書，確保每一句文案都有源可溯，徹底杜絕「漂綠」(Greenwashing) 的風險。</li>
                </ul>`,
                        '在永續時代，有效的溝通能力是將「環境效益」轉化為「品牌資產」與「市場溢價」的關鍵。此工具能極大地降低永續部門與行銷部門之間的溝通成本，確保對外溝通的專業性、一致性與合規性。',
                        `我們建議的標準作業流程(SOP)如下：
                <ol>
                    <li>首先，與您的行銷團隊共同確認產品的「<b>故事原型</b>」。</li>
                    <li>接著，在「<b>目標受眾溝通指南</b>」頁籤中，為您的目標客群找出最能打動他們的幾個溝通亮點。</li>
                    <li>最後，在撰寫文案或設計包裝時，從「<b>『數據到聲明』證據產生器</b>」頁籤中，直接引用可驗證的數據聲明，為您的行銷活動提供最強大的信任背書。</li>
                </ol>`,
                        formula_story
                    );
                    break;
                case 'sankey-analyzer-dashboard':
                    title = '解讀：多維度桑基圖分析儀';
                    let formula_sankey = `這是一個視覺化工具，而非單一分數卡片。其核心概念為：
流束寬度 ∝ 該節點的量化數值 (例如: 重量kg, 成本$, 碳排kg CO₂e)`;
                    body = build_html(
                        '這是一個進階的「<span class="highlight-term">多維度分析</span>」工具，旨在將產品的物質流，從不同商業與永續視角進行診斷，將數據轉化為決策。',
                        `此儀表板包含五種分析視角(頁籤)，各自回答一個核心的策略問題：
                <ul>
                    <li><b>物質流與循環性：</b>我的產品「循環度」如何？</li>
                    <li><b>氣候衝擊分析：</b>我的產品「氣候衝擊」有多大？</li>
                    <li><b>財務績效衝擊：</b>我的永續決策對「財務績效」有何影響？</li>
                    <li><b>供應鏈韌性診斷：</b>哪個材料是影響「供應鏈穩定性」的最大風險因子？</li>
                    <li><b>水資源管理視角：</b>我的產品在哪個環節暴露於「水資源風險」之下？</li>
                </ul>`,
                        '此工具將桑基圖從單一的視覺化圖表，提升為一個多功能的「<span class="highlight-term">策略沙盤</span>」。它讓決策者能快速切換視角，在成本、風險、環境衝擊之間進行權衡，從而做出更全面、更穩健的設計與採購決策。',
                        '請善用頂部的「<span class="highlight-term">頁籤</span>」來切換不同的分析視角。利用底部的「<span class="highlight-term">動態模擬器</span>」，即時演算調整再生料比例對各項指標的影響。',
                        formula_sankey
                    );
                    break;
                case 'comprehensive-sg-dashboard':
                    title = '解讀：供應鏈 S&G 風險儀表板';
                    // 【核心升級】新增 S & G 風險分數的計算公式
                    let formula_sg_dashboard = `單項S風險 = (勞工實務×40%) + (職業健康×40%) + (人權保障×20%)
總體S風險 = Σ (單項S風險 × 物料重量) / 總重量
---
單項G風險 = (商業道德×40%) + (資訊透明×40%) + (供應鏈韌性×20%)
總體G風險 = Σ (單項G風險 × 物料重量) / 總重量`;
                    body = build_html(
                        '這是一個整合式的「<span class="highlight-term">供應鏈風險診斷儀表板</span>」。它是一個有敘事流程的分析工具，旨在回答關於 S&G 風險的三個核心問題：<b>What</b> (風險有多高？), <b>Who</b> (誰是主要貢獻者？), <b>Why</b> (風險的組成結構是什麼？)。',
                        `儀表板的佈局設計緊扣這個分析流程：
        <ul>
            <li><b>左側 - 診斷摘要：</b>回答「<b>What</b>」(總體風險分數)與「<b>Who</b>」(Top 3 風險貢獻來源)。</li>
            <li><b>右側 - 視覺化分析：</b>用風險矩陣圖回答「<b>Why</b>」，呈現每個物料的風險定位。</li>
        </ul>`,
                        '此儀表板是企業進行供應鏈「<span class="highlight-term">盡職調查 (Due Diligence)</span>」時，區分優先次序的關鍵工具。它幫助您將有限的管理資源，聚焦在風險最高的環節上。',
                        `我們建議採用以下三步驟的決策流程：
        <ol>
            <li>首先，查看左側的「<b>核心風險指標</b>」，了解總體風險水平。</li>
            <li>接著，從「<b>Top 3 風險貢獻來源</b>」列表中，锁定您的首要管理目標。</li>
            <li>最後，在右側的「<b>風險矩陣圖</b>」中找到這個目標，分析它的風險組成，並據此制定您的供應商議合策略。</li>
        </ol>`,
                        formula_sg_dashboard
                    );
                    break;
                case 'sankey-chart-deep-dive':
                    title = '解讀：產品物質/碳流分析';
                    const mode = $('input[name="sankey-mode"]:checked').val() || 'mass';

                    if (mode === 'mass') {
                        const recycled_pct = (data.charts.content_by_type.recycled / data.inputs.totalWeight * 100);
                        const eol_recycle_pct = data.inputs.eol_scenario.recycle;

                        let diagnosis_html = '<ul>';
                        if (recycled_pct < 30) {
                            diagnosis_html += `<li><strong>診斷為「線性經濟依賴型」產品：</strong>再生料佔比僅 <b>${recycled_pct.toFixed(1)}%</b>，代表產品高度依賴地球有限資源，供應鏈韌性較低。</li>`;
                        } else {
                            diagnosis_html += `<li><strong>診斷為「循環經濟實踐型」產品：</strong>再生料佔比達 <b>${recycled_pct.toFixed(1)}%</b>，在生命週期的前端已具備良好的循環經濟基礎。</li>`;
                        }
                        if (eol_recycle_pct < 50) {
                            diagnosis_html += `<li><strong>診斷為「末端價值逸散」：</strong>生命終端的回收率目標僅 <b>${eol_recycle_pct.toFixed(1)}%</b>，大量的物質價值在廢棄後未能被回收，造成資源浪費。</li>`;
                        }
                        diagnosis_html += '</ul>';

                        body = build_html(
                            '此模式聚焦於產品的<b>物理組成</b>與<b>循環經濟潛力</b>。左側代表「原料來源」，右側代表生命終結後的「物質去向」。',
                            diagnosis_html,
                            '核心策略應是最大化「閉環流動」。降低對原生料的依賴，是提升供應鏈穩定性、抵禦原料價格波動風險的關鍵策略。一個高比例閉環的桑基圖，是溝通企業循環經濟承諾的最有力視覺證據。',
                            '<ul class="small"><li><b>若原生料佔比高：</b>立即與採購及研發部門合作，將「導入再生材料」作為關鍵績效指標(KPI)，並尋找高品質的再生料供應商。</li><li><b>若末端損失高：</b>重新檢視產品的「可回收性設計」(Design for Recyclability)，評估是否因使用了複合材料或黏膠，而導致產品難以被回收體系有效處理。</li></ul>'
                        );
                    } else { // mode === 'carbon'
                        const prod_co2 = data.charts.lifecycle_co2.production;
                        const eol_co2 = data.charts.lifecycle_co2.eol;
                        const total_co2 = data.impact.co2;
                        const top_emitter = [...data.charts.composition].sort((a,b) => b.co2 - a.co2)[0];

                        let diagnosis_html = '<ul>';
                        if(Math.abs(prod_co2) > Math.abs(eol_co2) * 2) {
                            diagnosis_html += `<li><strong>診斷為「前端主導型碳足跡」：</strong>產品 <b>${(prod_co2/total_co2 * 100).toFixed(0)}%</b> 的氣候衝擊在出廠前就已決定。</li>`;
                        }
                        if(top_emitter && (top_emitter.co2 / prod_co2) > 0.4) {
                            diagnosis_html += `<li><strong>診斷為「關鍵碳排熱點」：</strong>單一物料 <b>"${escapeHtml(top_emitter.name)}"</b> 就貢獻了超過 <b>${(top_emitter.co2 / prod_co2 * 100).toFixed(0)}%</b> 的生產碳排。</li>`;
                        }
                        if(eol_co2 < 0) {
                            diagnosis_html += `<li><strong>診斷為「循環效益驅動型減碳」：</strong>末端回收策略成功創造了 <b>${eol_co2.toFixed(2)} kg CO₂e</b> 的碳信用，有效降低了總碳足跡。</li>`;
                        }
                        diagnosis_html += '</ul>';

                        body = build_html(
                            '此模式聚焦於產品的<b>氣候衝擊</b>與<b>減碳路徑</b>。它將生命週期各階段的溫室氣體排放量視覺化，呈現了各環節的碳排放貢獻與減量效益。',
                            diagnosis_html,
                            '此圖揭示了產品減碳的「槓桿點」。若為「前端主導型」，策略應聚焦於低碳材料採購。識別出的「關鍵碳排熱點」既是最大風險，也是最高效的減碳機會點。此分析是企業制定科學基礎減碳目標(SBTi)時，將宏觀承諾落實到產品級行動的關鍵依據。',
                            '<ul class="small"><li><b>識別並處理熱點：</b>找出圖中最寬的碳排來源物料，列為第一優先改善目標。</li><li><b>行動方案：</b>針對該熱點，立即啟動：1) 導入其「再生料」的可行性評估。2) 尋找本身碳密度更低的「替代材料」。3) 探討「輕量化設計」的可能性。</li></ul>'
                        );
                    }
                    break;
                // 【新增解讀】
                case 'sustainability-deep-dive':
                    title = '解讀：永續性深度剖析模組';
                    body = build_html(
                        `此模組採用「<span class="highlight-term">三幕劇</span>」的敘事結構，引導您從宏觀到微觀，深入挖掘產品的環境衝擊來源與改善路徑。`,
                        `每一幕都有其獨特的策略目標：
                        <ul>
                            <li><strong>第一幕 (宏觀掃描)：</strong>解構產品的「<span class="highlight-term">材料基因</span>」，從材料大類視角，快速識別出衝擊與重量不成比例的材料家族，確立初步的策略方向。</li>
                            <li><strong>第二幕 (精準定位)：</strong>應用帕雷托法則，將所有零組件進行矩陣分析，精準鎖定造成80%環境衝擊的那20%關鍵「<span class="highlight-term">熱點</span>」與「<span class="highlight-term">隱形殺手</span>」。</li>
                            <li><strong>第三幕 (路徑追溯)：</strong>透過瀑布圖，清晰地講述產品的「<span class="highlight-term">碳排故事</span>」，量化每個組件的正向衝擊與負向貢獻(碳信用)，找出減碳的英雄與反派。</li>
                        </ul>`,
                        `這個由宏觀到微觀的分析流程，是一個標準化且高效率的<span class="highlight-term">生態設計診斷方法</span>。它確保您的優化策略不是隨機的，而是基於數據、聚焦重點、且路徑清晰的。`,
                        `請依序跟隨三幕劇的引導。從第一幕找到問題的大方向，在第二幕鎖定具體目標，於第三幕量化改善效益。這將構成您對內、對外溝通永續設計成果時最有力的故事線。`
                    );
                    break;
                // 【新增解讀】
                case 'cost-benefit-deep-dive':
                    title = '解讀：成本效益深度剖析模組';
                    body = build_html(
                        `此模組與「永續性深度剖析」平行，同樣採用「三幕劇」結構，但完全從「財務」與「商業決策」的視角出發，旨在連結永續性與成本效益。`,
                        `每一幕的商業目標如下：
                        <ul>
                            <li><b>第一幕 (宏觀掃描)：</b>回答「錢主要花在哪裡？」，快速定位佔據不成比例預算的「<span class="highlight-term">財務熱點</span>」組件。</li>
                            <li><b>第二幕 (精準定位)：</b>將成本與碳排連結，找出「高成本、高碳排」的雙重熱點，這些是能同時創造財務與環境效益的「<span class="highlight-term">Win-Win</span>」機會點。</li>
                            <li><b>第三幕 (效益分析)：</b>直接量化您的永續策略是省錢還是花錢。它透過與100%原生料的基準比較，計算出您產品的「<span class="highlight-term">綠色溢價</span>」或「<span class="highlight-term">綠色折扣</span>」。</li>
                        </ul>`,
                        `這個模組是將LCA分析從「環安衛部門」的工具，提升為「產品經理」與「CFO」都能看懂的<span class="highlight-term">商業決策儀表板</span>的關鍵。它用最直接的財務語言，來證明永續策略的商業合理性。`,
                        `請利用此模組來回答管理層最關心的問題。用第一幕的數據優化採購策略，用第二幕的洞察尋找Win-Win機會，用第三幕的結果來量化您的「<span class="highlight-term">綠色ROI</span>」(投資報酬率)。`
                    );
                    break;

                // 【新增解讀】
                case 'sankey-chart':
                    title = '解讀：產品物質流桑基圖 (Sankey)';
                    body = build_html(
                        '桑基圖是一種「<span class="highlight-term">物質流分析</span>」的視覺化工具。它以流程圖的形式，呈現產品生命週期中所有材料的「來龍」與「去脈」。',
                        '<ul><li>圖表最左側是「<span class="highlight-term">輸入端</span>」，顯示構成產品的原料是來自「原生料」還是「再生料」。</li><li>中間是您的「<span class="highlight-term">產品</span>」本身，所有輸入端的流量都會匯集於此。</li><li>最右側則是「<span class="highlight-term">輸出端</span>」，顯示產品在生命終結後，依據您設定的EOL情境，分別有多少比例的物質進入「回收」、「焚化」或「掩埋」流程。</li><li>所有「流帶」的寬度，都與其代表的物質重量成正比。</li></ul>',
                        '此圖是評估與溝通「<span class="highlight-term">循環經濟</span>」績效最直觀的工具。一個理想的循環經濟產品，其桑基圖應呈現「再生料」輸入比例高、且「回收(EOL)」輸出比例也高的特徵。它幫助您將抽象的循環概念，轉化為可被量化的物質流動。',
                        '若要提升循環績效，您的策略應雙管齊下：1. <b>擴大左側「再生料」的流量</b>：與採購部門合作，導入更多再生材料。 2. <b>擴大右側「回收(EOL)」的流量</b>：透過循環設計，確保您的產品更容易被回收再利用。'
                    );
                    break;

                // 【新增解讀】
                case 'sg-hotspot-chart':
                    title = '解讀：社會與治理風險熱點 (S&G)';
                    body = build_html(
                        '此圖表旨在識別出對您產品整體的「<span class="highlight-term">社會(S)</span>」與「<span class="highlight-term">治理(G)</span>」風險貢獻最大的物料組件，也就是您的「<span class="highlight-term">供應鏈聲譽風險熱點</span>」。',
                        '<ul><li>圖中的每一根長條代表一個物料組件。</li><li>長條的總長度，代表該物料對 S+G 總風險的「<span class="highlight-term">總貢獻度 (%)</span>」。</li><li>長條由兩種顏色組成：黃色代表「社會風險」的貢獻，灰色則代表「治理風險」的貢獻。</li></ul>',
                        '此分析是企業進行供應鏈「<span class="highlight-term">盡職調查 (Due Diligence)</span>」時，區分優先次序的關鍵工具。與其對所有供應商進行同樣強度的審核，此圖幫助您將有限的管理資源，聚焦在風險最高的幾個環節上，達成事半功倍的效果。',
                        '請將圖表中排序最靠前的 1-3 個物料，列為您供應商管理與稽核的「<span class="highlight-term">第一優先級清單</span>」。針對這些熱點，您應啟動更深入的調查，例如要求供應商提供社會責任稽核報告 (如 SA8000) 或治理相關證明。'
                    );
                    break;

                case 'social-scorecard':
                    title = '解讀：社會責任(S)風險細項';
                    let formula_s = `單項綜合風險 = (勞工實務風險×40%) + (職業健康風險×40%) + (人權保障風險×20%)
總體風險分數 = Σ (單項綜合風險 × 物料重量) / 總重量`;
                    body = build_html(
                        '此儀表板是「企業永續供應鏈聲譽儀表板」的下鑽分析，旨在深入探究造成「社會(S)風險」的<span class="highlight-term">根本原因</span>。',
                        '<ul><li><b>總體風險分數：</b>綜合所有細項後，計算出的產品加權平均社會風險分數 (0-100，越高越差)。</li><li><b>細項風險分數：</b>將總體風險拆解為「勞工實務」、「職業健康」、「人權保障」等不同子構面。</li><li><b>主要風險貢獻來源：</b>識別出是哪個物料對總體社會風險的貢獻最大。</li></ul>',
                        '此細項分析能幫助您避免籠統地談論「社會風險」，而是能精準地指出問題所在，使得改善策略更具針對性。',
                        '請優先關注分數最高的「<span class="highlight-term">細項風險</span>」，並結合「<span class="highlight-term">主要風險貢獻來源</span>」列表，找出需要優先進行供應商議合或稽核的具體目標。',
                        formula_s
                    );
                    break;

                case 'governance-scorecard':
                    title = '解讀：企業治理(G)風險細項';
                    let formula_g = `單項綜合風險 = (商業道德風險×40%) + (資訊透明風險×40%) + (供應鏈韌性風險×20%)
總體風險分數 = Σ (單項綜合風險 × 物料重量) / 總重量`;
                    body = build_html(
                        '此儀表板是「企業永續供應鏈聲譽儀表板」的下鑽分析，旨在深入探究造成「治理(G)風險」的<span class="highlight-term">根本原因</span>。',
                        '<ul><li><b>總體風險分數：</b>綜合所有細項後，計算出的產品加權平均治理風險分數 (0-100，越高越差)。</li><li><b>細項風險分數：</b>將總體風險拆解為「商業道德」、「資訊透明」、「供應鏈韌性」等不同子構面。</li><li><b>主要風險貢獻來源：</b>識別出是哪個物料對總體治理風險的貢獻最大。</li></ul>',
                        '健全的企業治理是長期價值的基石。此細項分析幫助您識別供應鏈中潛在的治理弱點，對於建立一個穩健的供應鏈至關重要。',
                        '請優先關注分數最高的「<span class="highlight-term">細項風險</span>」，並結合「<span class="highlight-term">主要風險貢獻來源</span>」列表，找出需要優先進行供應商管理與風險分散策略的具體目標。',
                        formula_g
                    );
                    break;

                // 【新增解讀】
                case 'total-water-footprint':
                    title = '解讀：總體水足跡子卡';
                    body = build_html(
                        '此儀表板提供了產品在「水資源」議題上更細緻的營運數據，是對「綜合水資源管理計分卡」的補充說明。',
                        '<ul><li><b>總用水量 (L)：</b>也稱為「藍水足跡」，指產品在生命週期中，直接消耗的地表水與地下水總量。</li><li><b>總取水量 (m³)：</b>指從環境中（如河流、湖泊）取用的總水量，包含消耗掉與最終返回環境的部分。</li><li><b>總廢水排放 (m³)：</b>指經過生產過程後，排放回環境的水量。</li></ul>',
                        '這三個指標共同描繪了您的產品與水資源之間的完整互動關係。一個理想的永續產品，不僅總用水量要低，其取水量與廢水排放量也應被妥善管理，以降低對地方水文的衝擊。',
                        '請利用「AI智慧洞察」的結論，找出這三個指標中表現最弱的環節，並將其作為您水資源管理策略的優先改善目標。'
                    );
                    break;

                // 【新增解讀】
                case 'climate-action-scorecard':
                    title = '解讀：氣候行動計分卡';
                    let formula_climate = `註：為公平比較，此分數僅計算「生產製造相關碳排」(已排除使用階段)。
氣候行動分數 = ( (原生料生產碳排 - 當前設計生產碳排) / 原生料生產碳排 ) * 100`;
                    body = build_html(
                        '此計分卡是您產品在「<span class="highlight-term">氣候變遷</span>」議題上的專屬儀表板，深度剖析碳足跡的來源與減碳效益。',
                        '<ul><li><b>氣候行動分數：</b>綜合評分(0-100)，分數越高代表您的設計相較於「100%原生料」基準，在減碳上的成效越卓越。</li><li><b>生命週期階段佔比：</b>將總碳足跡拆解為「生產製造」與「廢棄處理」等階段，幫助您識別主要的衝擊來源是在上游還是下游。</li><li><b>主要碳排貢獻來源：</b>採用80/20法則，找出對總碳足跡貢獻最大的前五個物料組件，也就是您的「碳排熱點」。</li></ul>',
                        '此計分卡是企業制定「<span class="highlight-term">科學基礎減碳目標(SBTi)</span>」與回應「<span class="highlight-term">氣候相關財務揭露(TCFD)</span>」時，最關鍵的產品級數據儀表板。它將宏觀的氣候承諾，落實到具體的產品設計與供應鏈管理行動中。',
                        '請優先處理「<span class="highlight-term">主要碳排貢獻來源</span>」中排名第一的熱點物料。針對該物料進行再生料比例提升、輕量化設計或低碳替代方案評估，是最高效的減碳策略。',
                        formula_climate
                    );
                    break;

                // 【新增解讀】
                case 'comprehensive-circularity-scorecard':
                    title = '解讀：綜合循環經濟計分卡';
                    let formula_comp_circ = `綜合循環經濟分數 = (MCI分數 × 50%) + (生產廢棄物改善分數 × 25%) + (資源消耗改善分數 × 25%)`;
                    body = build_html(
                        '此儀表板旨在提供一個比單純的「物質循環指數(MCI)」更全面的循環經濟績效視圖。',
                        '<ul><li><b>綜合循環經濟分數：</b>這是一個加權平均分數，整合了以下三個核心構面，旨在提供一個更平衡的績效評估。</li><li><b>三大核心構面分析：</b><ol><li><b>MCI (產品循環設計)：</b>評估產品在「設計」層面是否有利於循環，包含再生料投入與回收性。</li><li><b>生產廢棄物改善：</b>評估產品在「生產」過程中的資源使用效率與廢棄物管理表現。</li><li><b>資源消耗(ADP)改善：</b>評估產品對地球「有限礦物資源」的依賴程度，分數越高代表依賴越低。</li></ol></li></ul>',
                        '一個真正成功的循環經濟策略，不應只關注產品本身的回收性(MCI)，還應兼顧生產過程的效率與對稀缺資源的保護。此綜合儀表板能幫助您避免「設計看似循環，但生產過程卻極度浪費」的策略盲點。',
                        '請利用「AI智慧洞察」的結論，找出您在這三大構面中的「<span class="highlight-term">策略短版</span>」。將資源集中投入到最弱的環節，是提升整體循環經濟表現最有效率的方式。',
                        formula_comp_circ
                    );
                    break;
                case 'pollution-prevention-overview':
                    title = '解讀：綜合污染防治計分卡';
                    let formula_pollution = `單項改善分數 = ( (原生料衝擊 - 當前衝擊) / 原生料衝擊 ) * 100
污染防治總分 = (空污分數 + 土壤分數 + 優養化分數 + 酸化分數 + 生態毒性分數) / 5`;
                    body = build_html(
                        '此儀表板是您產品在「<span class="highlight-term">污染防治</span>」議題上的績效總覽。它超越了單純的溫室氣體盤查，深入評估產品生命週期中，對空氣、水、土壤等多個環境介質的綜合衝擊。',
                        `此儀表板包含三個核心部分：
                        <ul>
                            <li><strong>污染防治總分：</strong>這是一個綜合評分(0-100)，分數越高代表您的設計相較於「100%原生料」基準，在污染控制上的成效越卓越。</li>
                            <li><strong>五大污染類型表現：</strong>長條圖將總分拆解為五個關鍵的污染子項目：
                                <ol>
                                    <li><b>非溫室氣體空氣污染：</b>如 SOx, NOx 等影響空氣品質的污染物。</li>
                                    <li><b>土壤污染：</b>有害物質進入土壤生態系統的潛力。</li>
                                    <li><b>優養化：</b>氮、磷等物質進入水體，導致生態失衡的潛力。</li>
                                    <li><b>酸化：</b>俗稱「酸雨」，主要由 SOx, NOx 造成。</li>
                                    <li><b>生態毒性：</b>化學物質對生態系統的潛在毒性影響。</li>
                                </ol>
                            </li>
                            <li><strong>AI 智慧洞察：</strong>演算法會自動找出您在這五大類型中表現最弱的「<span class="highlight-term">策略短版</span>」。</li>
                        </ul>`,
                        '一個高分的污染防治表現，代表您的產品具備「<span class="highlight-term">清潔生產</span>」的特質。這不僅有助於符合日益嚴格的地方環保法規（如空污法、水污法），更能提升品牌在消費者與在地社區心中的信譽，是企業社會責任的具體展現。',
                        `請利用「AI 智慧洞察」的結論，找出您在這五大構面中的「<span class="highlight-term">最弱環節</span>」。將改善資源集中於此，將是提升整體污染防治分數最高效的方式。例如，若弱點是「優養化」，則應優先檢視供應鏈中與農業（如棉花）或濕式製程（如染色）相關的環節。`,
                        formula_pollution
                    );
                    break;
                case 'environmental-performance-overview':
                    title = '解讀：環境績效總覽儀表板';
                    let formula_e_score = `綜合環境分數(E) = (氣候分數×25%) + (循環分數×25%) + (水管理分數×20%) + (污染防治分數×15%) + (自然資本分數×15%)`;
                    body = build_html(
                        '此儀表板是您產品在「<span class="highlight-term">環境(E)</span>」面向的最高層級總結，是 ESG 總分中 E 分數的核心來源。它將多個複雜的環境指標，整合為一個綜合分數與五個易於理解的策略支柱。',
                        `此儀表板的每個部分都在回答一個關鍵問題：
                        <ul>
                            <li><strong>綜合環境分數：</strong>我的產品整體環境表現如何？(0-100分，越高越好)</li>
                            <li><strong>頂層 KPI 區塊：</strong>與傳統設計相比，我在「碳、水、能」這三個最受關注的指標上表現如何？</li>
                            <li><strong>五大構面分析：</strong>我的環境績效優勢在哪裡？短版又在哪裡？這五大構面分別代表：
                                <ol>
                                    <li><b>氣候行動：</b>減碳成效。</li>
                                    <li><b>循環經濟：</b>資源利用與廢棄物管理效率。</li>
                                    <li><b>水資源管理：</b>水資源消耗與稀缺性衝擊。</li>
                                    <li><b>污染防治：</b>各類污染物（如酸雨、優養化）的排放控制。</li>
                                    <li><b>自然資本：</b>對生物多樣性與土地利用的衝擊。</li>
                                </ol>
                            </li>
                        </ul>`,
                        '在策略層面，此儀表板幫助您建立一個「<span class="highlight-term">平衡且全面</span>」的環境策略，有效避免「<span class="highlight-term">衝擊轉移</span>」（例如，為降低碳排而大幅增加水污染）。一個均衡且高分的表現，是向利害關係人證明您擁有系統性永續管理能力的最佳證據。',
                        `我們建議採用以下決策流程：
                        <ol>
                            <li>首先，檢視「<span class="highlight-term">綜合環境分數</span>」以獲得總體印象。</li>
                            <li>接著，從「<span class="highlight-term">五大構面分析</span>」圖表中，找出分數最低的那個構面——這就是您的「策略短版」。</li>
                            <li>最後，點擊進入該構面更詳細的計分卡（例如，若「水資源管理」分數低，則檢視「綜合水資源管理計分卡」），進行根本原因分析並制定改善計畫。</li>
                        </ol>`,
                        formula_e_score
                    );
                    break;

                case 'water-management-overview':
                    title = '解讀：綜合水資源管理計分卡';
                    let formula_water_management = `水管理總分 = (水稀缺性分數 × 40%) + (總用水量分數 × 20%) + (總取水量分數 × 20%) + (總廢水排放分數 × 20%)`;
                    body = build_html(
                        '此儀表板是您產品在「<span class="highlight-term">水資源管理</span>」議題上的專屬戰情室。它整合了水量、水質與水風險，提供一個比單純計算用水量更全面、更具策略價值的視圖。',
                        `此計分卡的數據洞察來自其多維度的評估框架：
                        <ul>
                            <li><strong>水資源管理總分：</strong>綜合評估產品在水資源議題上的整體表現。</li>
                            <li><strong>四大構面分數：</strong>將總分拆解為四個核心，幫助您理解分數的由來：
                                <ol>
                                    <li><b>水資源稀缺性(AWARE)：</b>評估您的用水是否發生在「地理上的」高風險缺水地區。</li>
                                    <li><b>總用水量/取水量：</b>評估「營運上」的用水效率。</li>
                                    <li><b>總廢水排放：</b>評估對地方水質的潛在衝擊。</li>
                                </ol>
                            </li>
                            <li><strong>AI 智慧洞察：</strong>最關鍵的部分。AI 會進行一次「<span class="highlight-term">雙維度風險評估</span>」，結合您的「營運依賴度」與「地理稀缺性」，診斷出您的產品是否暴露於最危險的「雙重水風險」之中。</li>
                        </ul>`,
                        '在全球水資源日益緊張的背景下，水風險已成為關鍵的「<span class="highlight-term">營運風險</span>」。此儀表板是企業強化供應鏈韌性、回應「<span class="highlight-term">CDP 水安全問卷</span>」以及與利害關係人溝通水資源承諾的核心工具。',
                        `您的決策流程應為：
                        <ol>
                            <li>首先，閱讀「<span class="highlight-term">AI 智慧洞察</span>」，快速掌握您最核心的水風險策略定位。</li>
                            <li>接著，檢視「<span class="highlight-term">四大構面分數</span>」，找出造成目前風險定位的根本原因。</li>
                            <li>最後，針對分數最低的構面制定改善策略。例如，若「水資源稀缺性」分數低，您的策略應是更換供應來源地；若「總用水量」分數低，策略則應是改善製程效率。</li>
                        </ol>`,
                        formula_water_management
                    );
                    break;

                case 'tnfd-dashboard':
                    title = '解讀：TNFD自然風險儀表板';
                    let formula_tnfd = `單項風險 = 材料重量 × 來源國風險分數 × (1 - 再生料減免係數)
總風險分數 = Σ (所有單項風險) / 總重量`;
                    let def_tnfd = `此儀表板是您回應「<span class="highlight-term">自然相關財務揭露 (TNFD)</span>」框架的策略核心。它旨在將抽象的「自然風險」轉化為可量化、可管理的商業指標，幫助您識別、評估並應對因生物多樣性喪失和生態系統退化而產生的營運、法規與市場風險。`;
                    let insight_tnfd = `儀表板的每個區塊都提供了不同層次的洞察：
        <ul>
            <li><strong>頂部三項指標：</strong>這是您的「高階管理層快照」。它總結了整體的<span class="highlight-term">風險水平</span>、<span class="highlight-term">潛在財務衝擊(VaR)</span>以及可供發展的<span class="highlight-term">自然機會</span>。</li>
            <li><strong>主要風險貢獻來源：</strong>這是整個儀表板中「<span class="highlight-term">最具可操作性</span>」的部分。它採用80/20法則，精準地告訴您，是「哪個國家的哪個材料」對您的總風險貢獻最大。</li>
            <li><strong>風險與機會圖表：</strong>視覺化呈現您的供應鏈面臨的前五大具體風險（如：水資源壓力）與潛在機會（如：生態系統復育）。</li>
            <li><strong>依賴與衝擊路徑：</strong>揭示了您的商業模式與自然之間的根本連結。即：您的營運<span class="highlight-term">依賴</span>哪些生態系統服務，而這些活動又對自然<span class="highlight-term">產生</span>了哪些衝擊。</li>
            <li><strong>AI 智慧洞察：</strong>由演算法自動生成的總結，為您提供基於數據的、最直接的策略性建議。</li>
        </ul>`;
                    let strat_tnfd = `在策略層面，此儀表板是您強化「<span class="highlight-term">供應鏈韌性</span>」的關鍵工具。透過它，您可以主動識別出那些可能因氣候變遷或生態破壞而中斷的供應環節，並提前佈局替代方案。同時，它也是一個強大的「<span class="highlight-term">利害關係人溝通</span>」工具，能向投資者和客戶證明您對管理自然風險的承諾與能力。`;
                    let advice_tnfd = `我們建議採用以下三步驟的決策流程：
        <ol>
            <li><strong>從「AI 智慧洞察」開始：</strong>快速獲取儀表板的結論與核心建議。</li>
            <li><strong>聚焦「主要風險貢獻來源」：</strong>將此列表作為您下一步供應商盡職調查和風險管理的優先級清單。</li>
            <li><strong>探索「主要自然機會」：</strong>與您的產品或行銷團隊討論，如何將這些機會轉化為創新產品或品牌故事，創造新的商業價值。</li>
        </ol>`;
                    body = build_html(def_tnfd, insight_tnfd, strat_tnfd, advice_tnfd, formula_tnfd);
                    break;
                case 'water-scarcity-scorecard':
                    title = '解讀：水資源短缺足跡(AWARE)子卡';
                    let formula_water_aware = `改善分數 = ( (原生料AWARE足跡 - 當前設計AWARE足跡) / 原生料AWARE足跡 ) * 100`;
                    let def_water_aware = `此計分卡採用國際公認的「<span class="highlight-term">AWARE</span>」(Available Water Remaining)方法學，評估產品生命週期對全球水資源的「<span class="highlight-term">稀缺性</span>」影響。它超越了傳統的用水量計算，將「在哪裡用水」納入考量，更能反映真實的「<span class="highlight-term">水風險</span>」。`;
                    let insight_water_aware = `儀表板上的各項指標分別代表：
                        <ul>
                            <li><strong>改善表現分數：</strong>一個綜合評分(0-100)，分數越高代表您的設計相較於「100%原生料」基準，對水資源短缺地區的衝擊越低。</li>
                            <li><strong>總水資源短缺足跡 (m³ eq.)：</strong>這是經過風險加權後的總水耗。1 m³ eq. 代表其衝擊約當於在全球平均水資源地區消耗 1 立方米的水。</li>
                            <li><strong>主要貢獻來源：</strong>採用80/20法則，找出對水資源短缺足跡貢獻最大的物料組件，它們是您最需要關注的「水風險熱點」。</li>
                        </ul>`;
                    let strat_water_aware = `水資源短缺是全球性的營運風險。此計分卡幫助您識別供應鏈中是否存在「<span class="highlight-term">高水風險</span>」環節，提前應對因乾旱、水權爭議等因素造成的營運中斷風險，是企業回應「<span class="highlight-term">CDP 水安全問卷</span>」的關鍵數據。`;
                    let advice_water_aware = `若分數偏低或發現顯著熱點，您的行動建議如下：
                        <ol>
                            <li><strong>針對熱點物料：</strong>與該物料的供應商進行溝通，深入了解其生產廠區所在地的水資源狀況與水管理措施。</li>
                            <li><strong>尋求替代方案：</strong>優先選擇來自水資源豐富地區的供應商，或評估改用製程需水量較低的替代材料。</li>
                            <li><strong>提升再生比例：</strong>使用再生材料通常能大幅降低製程中的水消耗，是降低水風險的有效途徑。</li>
                        </ol>`;
                    body = build_html(def_water_aware, insight_water_aware, strat_water_aware, advice_water_aware, formula_water_aware);
                    break;
                case 'resource-depletion-scorecard':
                    title = '解讀：資源消耗(ADP)計分卡';
                    let formula_adp_resource = `改善分數 = ( (原生料ADP - 當前設計ADP) / 原生料ADP ) * 100`;
                    let def_adp_resource = `此計分卡採用「<span class="highlight-term">非生物資源消耗潛力</span>」(Abiotic Depletion Potential, ADP)指標，旨在評估您的產品對地球上有限的、非再生的礦物與化石燃料資源的消耗程度。單位為「<span class="highlight-term">公斤銻當量 (kg Sb-eq)</span>」，數值越高代表消耗越嚴重。`;
                    let insight_adp_resource = `儀表板上的各項指標分別代表：
                        <ul>
                            <li><strong>改善表現分數：</strong>一個綜合評分(0-100)，分數越高代表您的設計相較於「100%原生料」基準，對稀缺資源的依賴程度越低。</li>
                            <li><strong>總資源消耗潛力 (kg Sb-eq)：</strong>產品生命週期的總資源消耗量。此數值的大小直接反映了您的產品對地球有限礦藏的依賴程度。</li>
                            <li><strong>主要貢獻來源：</strong>找出對資源消耗貢獻最大的物料組件。通常，貴金屬、稀有金屬或特定工程塑膠會是主要的熱點。</li>
                        </ul>`;
                    let strat_adp_resource = `此指標是評估企業「<span class="highlight-term">長期供應鏈韌性</span>」與實踐「<span class="highlight-term">循環經濟</span>」的關鍵。高度依賴稀缺資源的產品，未來可能面臨價格劇烈波動與供應中斷的風險。降低ADP是企業永續經營的核心策略之一。`;
                    let advice_adp_resource = `若分數偏低或發現顯著熱點，您的行動建議如下：
                        <ol>
                            <li><strong>提升再生比例：</strong>最大化熱點材料的「再生材料」使用率，是降低ADP最直接、最有效的方法。</li>
                            <li><strong>尋求替代方案：</strong>評估使用地球儲量更豐富的材料（如鋼、鋁）或「生物基材料」來替代高ADP材料的可行性。</li>
                            <li><strong>延長產品壽命：</strong>透過「可維修性設計」或「耐久性設計」，延長產品的使用壽命，從根本上減少對新資源的需求。</li>
                        </ol>`;
                    body = build_html(def_adp_resource, insight_adp_resource, strat_adp_resource, advice_adp_resource, formula_adp_resource);
                    break;
                case 'biodiversity-scorecard':
                    title = '解讀：生物多樣性(B)計分卡';
                    let formula_bio = `壓力分數 = (土地利用改善分數 + 生態毒性改善分數) / 2
應對分數 = 50 + (正面行動貢獻) - (風險暴露貢獻)
最終表現分數 = (壓力分數 * 70%) + (應對分數 * 30%)`;
                    let def_bio = `此計分卡是評估您的產品對「<span class="highlight-term">自然資本</span>」(Natural Capital)影響的策略工具。它超越了傳統的碳或能源指標，專注於衡量產品生命週期對生態系統的潛在衝擊，是企業回應「<span class="highlight-term">自然相關財務揭露(TNFD)</span>」等新興框架的關鍵依據。`;
                    let insight_bio = `儀表板上的各項指標分別代表：
                <ul>
                    <li><strong>表現分數：</strong>一個綜合評分(0-100)，分數越高代表您的設計相較於「100%原生料」基準，對生物多樣性的衝擊越低。</li>
                    <li><strong>土地利用 (m²a)：</strong>代表生產原料所需的「土地面積-年」。此數值越高，可能意味著對森林砍伐、棲息地破壞的壓力越大。</li>
                    <li><strong>淡水生態毒性 (CTUe)：</strong>衡量在生命週期中釋放的化學物質，對水生生態系統的潛在毒性影響。數值越高，對河流、湖泊生態的潛在危害越大。</li>
                    <li><strong>熱點分析：</strong>採用80/20法則，分別找出對「土地利用」和「生態毒性」貢獻最大的物料組件，它們是您最需要關注的「衝擊熱點」。</li>
                </ul>`;
                    let strat_bio = `生物多樣性的喪失已被世界經濟論壇視為全球前五大長期風險之一。此計分卡幫助您將此宏觀風險，轉化為可管理的產品級指標。一個表現良好的產品，代表其具備更強的「<span class="highlight-term">供應鏈韌性</span>」（降低對受威脅生態系統的依賴），並能提升品牌在環保議題上的<span class="highlight-term">領導力</span>與<span class="highlight-term">可信度</span>。`;
                    let advice_bio = `若分數偏低或發現顯著熱點，您的行動建議如下：
                <ol>
                    <li><strong>針對「土地利用」熱點：</strong>優先考慮使用來自「永續認證」（如FSC森林認證）的原料，或提高再生材料比例以減少對新土地資源的需求。</li>
                    <li><strong>針對「生態毒性」熱點：</strong>與該材料的供應商進行溝通，深入了解其生產製程與污染控制措施。選擇採用「清潔生產」技術或擁有可靠環保認證的供應商。</li>
                    <li><strong>管理潛在風險：</strong>檢視儀表板「已識別的潛在風險」列表，並將其納入您的供應商盡職調查流程中。</li>
                </ol>`;
                    body = build_html(def_bio, insight_bio, strat_bio, advice_bio, formula_bio);
                    break;
                case 'commercial-dashboard': // 保留舊的 key 以相容
                case 'commercial-dashboard-v2':
                    title = '解讀：商業決策儀表板';
                    body = build_html(
                        '此儀表板是整個系統的「<span class="highlight-term">商業大腦</span>」，它將底層複雜的LCA環境數據，轉譯為高階管理者最關心的<span class="highlight-term">商業語言</span>，旨在回答：「我們的永續努力，在商業上是否可行且具備競爭力？」',
                        `此儀表板的資訊層次經過精心設計，由上到下分別是：
                        <ul>
                            <li><strong>頂層 KPI 區塊：</strong>這是您的「高階管理層快照」，直接總結了產品的<span class="highlight-term">最終獲利能力(淨利率)</span>、<span class="highlight-term">永續策略的財務影響</span>、以及將<span class="highlight-term">環境成本轉化為商業價值的效率</span>。</li>
                            <li><strong>成本結構分析：</strong>詳細列出了「單件」與「總計」的成本細項（包含材料、製造、管銷），環圈圖則將其視覺化，幫助您快速理解成本的組成。</li>
                            <li><strong>永續策略的財務影響：</strong>精準量化您的永續策略是帶來了<span class="highlight-term">成本節省(綠色折扣)</span>，還是需要<span class="highlight-term">額外投資(綠色溢價)</span>，並計算其對「淨利」的最終影響。</li>
                            <li><strong>AI 智慧洞察：</strong>演算法會綜合所有數據，為您提供一個關於「這筆永續投資是否划算」的質化總結。</li>
                        </ul>`,
                        '在策略層面，此儀表板是您制定「<span class="highlight-term">永續產品定價策略</span>」的核心依據。它將抽象的永續風險與效益，轉化為具體的<span class="highlight-term">淨利 (Net Profit)</span> 衝擊，是永續部門與財務部門之間最佳的溝通橋樑。',
                        `請利用此儀表板進行決策：
                        <ol>
                            <li><strong>從頂層 KPI 開始：</strong>快速評估「淨利率」是否健康，「對淨利的影響」是否在可接受範圍內。</li>
                            <li><strong>若淨利不如預期：</strong>請檢視「成本結構分析表」，找出佔比最高的成本項目，並將其作為首要的優化目標。</li>
                            <li><strong>持續優化「碳—淨利效率」：</strong>在不同設計方案間，選擇此效率指標最高的方案，代表您能以最低的環境代價，創造最大的商業利潤。</li>
                        </ol>`
                    );
                    break;
                case 'supply-chain-map':
                    title = '解讀：供應鏈風險地圖儀表板';
                    let formula_map = `這是一個地理視覺化工具，其核心指標計算概念如下：
供應鏈集中度(%) = (該國家的總重量 / 產品總重量) × 100
碳排集中度(%) = (該國家的總碳排 / 產品總碳排) × 100`;
                    let def_map = `此儀表板是一個策略性的「<span class="highlight-term">地理風險視覺化</span>」工具。它將您產品物料清單(BOM)的來源地，與其潛在的社會與治理(S&G)風險相結合，繪製成一張互動式的全球供應鏈風險地圖。`;
                    let insight_map = `此儀表板包含幾個核心的互動元素：
                        <ul>
                            <li><strong>總體報告：</strong>右側資訊欄預設顯示一份「<span class="highlight-term">供應鏈總體報告</span>」，摘要您對各國的依賴程度，包含「供應鏈集中度(依重量)」、「碳排集中度」與「社會風險來源國 Top 3」。</li>
                            <li><strong>互動式圖例：</strong>左下角的圖例不僅說明顏色對應的物料類別，當您將滑鼠懸停在某個類別上時，地圖上所有包含該類別的國家標記都會<span class="highlight-term">高亮顯示</span>。</li>
                        </ul>`;
                    let strat_map = `在策略層面，此地圖幫助您回答幾個關鍵問題：
                        <ol>
                            <li><b>風險集中度：</b>我的關鍵原料是否過度集中在某個高風險國家？</li>
                            <li><b>氣候韌性：</b>我的碳排放是否高度集中在特定國家？</li>
                            <li><b>盡職調查：</b>這張圖是企業進行供應鏈「<span class="highlight-term">盡職調查</span>」(Due Diligence)的起點。</li>
                        </ol>`;
                    let advice_map = `請利用此地圖進行「壓力測試」：從「總體報告」中，找出在三個Top 3列表中都出現的國家，該國是您<span class="highlight-term">最關鍵、風險也最高的供應來源</span>，應列為最優先的管理對象。`;
                    body = build_html(def_map, insight_map, strat_map, advice_map, formula_map);
                    break;
                case 'circularity-scorecard':
                    title = '解讀：循環經濟(C)計分卡';
                    // 【核心升級】新增「循環設計潛力」的計算公式
                    let formula_mci = `線性流失指數 (LFI) = (原生料投入 + 終端廢棄損失) / (2 * 總重量)
MCI 分數 = (1 - LFI) * 100
---
循環設計潛力(%) = Σ (各組件重量 × 該材料可回收率%) / 總重量`;
                    body = build_html(
                        '此計分卡基於「艾倫・麥克阿瑟基金會」(Ellen MacArthur Foundation) 的「<span class="highlight-term">物質循環指數</span>」(MCI) 簡化模型，旨在將產品的「循環程度」量化為一個直觀的分數。',
                        `儀表板上的各項指標分別代表：
        <ul>
            <li><strong>物質循環指數 (MCI)：</strong>這是您的核心總分 (0-100)。分數越高，代表產品的循環程度越高。</li>
            <li><strong>再生料投入：</strong>衡量產品在「<span class="highlight-term">生命週期前端</span>」的循環性。</li>
            <li><strong>終端回收率 (您的目標)：</strong>衡量產品在「<span class="highlight-term">生命週期末端</span>」的循環潛力，這是您為產品設定的回收目標。</li>
            <li><strong>循環設計潛力 (材料物理極限)：</strong>這是一個進階指標，它代表了基於您所選材料的「<span class="highlight-term">物理可回收率</span>」，您的產品在理論上最高能達到的回收率。</li>
        </ul>
        「AI 智慧洞察」的關鍵，在於比較「您的目標」與「物理極限」之間的差距。`,
                        'MCI 不僅是一個環保指標，更是一個「<span class="highlight-term">供應鏈韌性</span>」與「<span class="highlight-term">未來競爭力</span>」的指標。高循環度的產品能降低對價格波動劇烈的原生資源的依賴，更能滿足全球日益嚴格的法規要求。',
                        '請優先閱讀「<span class="highlight-term">AI 智慧洞察</span>」。它會告訴您目前的回收目標是過於保守（<span class="highlight-term">策略機會</span>），還是過於激進以至於有漂綠風險（<span class="highlight-term">策略警示</span>），並為您提供最直接的策略建議。',
                        formula_mci
                    );
                    break;
                case 'regulatory-risk-dashboard':
                    title = '解讀：法規風險儀表板';
                    body = build_html(
                        '此儀表板是一個「<span class="highlight-term">前瞻性風險壓力測試</span>」工具。它將您目前的產品設計，放入一個模擬的全球主流法規情境中，直接量化五項關鍵法規可能帶來的潛在財務成本與市場准入風險。',
                        `此儀表板的洞察涵蓋了三種不同類型的風險：
                        <ul>
                            <li><strong>財務成本型風險：</strong>直接對應到錢。
                                <ul>
                                    <li><b>歐盟 CBAM：</b>針對進口產品的「<span class="highlight-term">隱含碳排</span>」課稅。</li>
                                    <li><b>歐盟塑膠稅：</b>針對「<span class="highlight-term">原生塑膠包材</span>」課稅。</li>
                                </ul>
                            </li>
                            <li><strong>供應鏈合規型風險：</strong>要求您揭露與通報。
                                <ul>
                                    <li><b>歐盟 REACH (SVHC)：</b>若產品中含有「<span class="highlight-term">高度關注物質</span>」，您有法律義務向供應鏈下游通報。</li>
                                </ul>
                            </li>
                             <li><strong>市場准入型風險：</strong>若不符合，可能導致產品無法進入特定市場。
                                <ul>
                                    <li><b>歐盟毀林法規 (EUDR)：</b>禁止涉及「<span class="highlight-term">毀林</span>」的商品進入歐盟市場。</li>
                                    <li><b>美國防止強迫勞動法 (UFLPA)：</b>推定來自特定地區的產品涉及「<span class="highlight-term">強迫勞動</span>」，除非企業能提出反證。</li>
                                </ul>
                            </li>
                        </ul>`,
                        '在策略層面，此儀表板幫助您將抽象的「法規風險」轉化為可量化的「<span class="highlight-term">財務衝擊</span>」與「<span class="highlight-term">營運衝擊</span>」。這使得企業能主動、提前地管理供應鏈與設計，將未來的法規威脅，轉化為因應得當的競爭優勢。',
                        `請利用底部的「<span class="highlight-term">AI 智慧洞察</span>」，它已經為您總結了產品面臨的主要風險類型，並提供了具體的下一步行動建議。`
                    );
                    break;
                case 'esg-scorecard-v2':case 'esg-scorecard-v2':
                    title = '解讀：產品ESG永續績效';
                    let formula_esg = `S 績效分數 = 100 - S 風險分數
G 績效分數 = 100 - G 風險分數
ESG 綜合分數 = (E 績效分數 + S 績效分數 + G 績效分數) / 3`;
                    let def_esg = `這不僅是一張成績單，更是一個策略性的「產品永續健康診斷工具」。它將複雜的ESG議題，濃縮為一個直觀的評級與三大核心構面分數，幫助您快速評估產品的<span class="highlight-term">市場競爭力</span>與<span class="highlight-term">潛在風險</span>。`;
                    let insight_esg = `此報告最深刻的洞察，來自於 E、S 與 G 三個分數之間的「關係」與「平衡性」：
                        <ul>
                            <li><span class="highlight-term">高分且均衡</span>：代表產品具備全面且穩健的永續管理能力，風險低，品牌價值高。</li>
                            <li><span class="highlight-term">高分但不均衡</span>：例如，極高的 E 分數但極低的 S 分數，可能揭示了潛在的「<span class="highlight-term">衝擊轉移</span>」風險 — 亦即為了追求環境效益而犧牲了供應鏈的社會責任，這是一個需要警惕的策略盲點。</li>
                            <li><span class="highlight-term">分數普遍偏低</span>：代表產品的永續基底較為薄弱，可能在面對綠色採購、永續投資或嚴格法規時面臨挑戰。</li>
                        </ul>
                        儀表板的「AI 智慧洞察」區塊，已經為您將這些複雜的組合模式，總結成一個清晰的「<span class="highlight-term">產品永續畫像</span>」。`;
                    let strat_esg = `在策略層面，這份報告是一個「<span class="highlight-term">多維度的風險與機會地圖</span>」。一個高評級的產品是一項可量化的「<span class="highlight-term">綠色資產</span>」。反之，一個低評級的產品則是一項被量化的「<span class="highlight-term">潛在負債</span>」。`;
                    let advice_esg = `我們建議採用以下三步驟的分析流程：
                        <ol>
                            <li><strong>策略定調 (Triage)：</strong>首先閱讀儀表板的「AI 智慧洞察」，它為您產品的ESG表現提供了一個最直接、最精煉的策略性總結與畫像。</li>
                            <li><strong>診斷問題 (Diagnose)：</strong>接著，檢視三個儀表盤，快速找出分數最低、最需要關注的構面。</li>
                            <li><strong>深入探勘 (Deep Dive)：</strong>針對分數最低的構面，請檢視儀表板更下方的詳細計分卡進行<span class="highlight-term">根本原因分析</span>。</li>
                        </ol>`;
                    body = build_html(def_esg, insight_esg, strat_esg, advice_esg, formula_esg);
                    break;

                case 'corporate-reputation-dashboard':
                    title = '解讀：企業永續供應鏈聲譽儀表板';
                    let formula_reputation = `綜合 S&G 風險評分 = (社會(S)風險分數 + 治理(G)風險分數) / 2`;
                    let def_reputation = '此儀表板是一個高階的策略工具，旨在整合您產品供應鏈的「社會(S)」與「治理(G)」兩個面向的風險，提供一個綜合性的聲譽評估。';
                    let insight_reputation = `此儀表板包含三個核心部分：
                        <ul>
                            <li><strong>綜合 S&G 風險評分：</strong>提供一個快速的、頂層的風險快照 (0-100，分數越低越好)。</li>
                            <li><strong>聲譽定位：</strong>基於 S 和 G 分數的組合，為您的供應鏈賦予一個質化畫像，例如「<span class="highlight-term">ESG聲譽領導者</span>」。</li>
                            <li><strong>聲譽資產與風險敞口：</strong>彙總了所有正面實踐（如認證）與已識別的潛在風險。</li>
                        </ul>`;
                    let strat_reputation = `供應鏈的穩定性與企業聲譽緊密相連。此儀表板能幫助您主動識別並管理來自供應商的<span class="highlight-term">非財務風險</span>，強化<span class="highlight-term">供應鏈韌性</span>。`;
                    let advice_reputation = `如果綜合分數偏高，請優先審視「<span class="highlight-term">風險敞口</span>」中所列出的項目。與相關供應商展開對話，要求其提供改善計畫或相關的稽核報告。`;
                    body = build_html(def_reputation, insight_reputation, strat_reputation, advice_reputation, formula_reputation);
                    break;

                case 'storytelling-scorecard':
                    title = '解讀：永續故事力™ 總評';
                    def = '此模組是一個獨創的「元分析」(Meta-Analysis) 工具。它評估的不是產品有多環保，而是您的產品「環保故事」的<span class="highlight-term">強度、可信度與傳播潛力</span>。';
                    insight = `此總評綜合了多個維度：
                        <ul>
                            <li><strong>綜合評分與評級：</strong>快速衡量您的永續故事是否強而有力 (S+ 到 D 級)。</li>
                            <li><strong>永續定位：</strong>基於數據，為您的產品賦予一個專業的定位名稱，例如「氣候績效領先型」或「低效益循環模式」。</li>
                            <li><strong>故事優勢：</strong>提煉出您最值得對外溝通的<span class="highlight-term">行銷亮點</span>。</li>
                            <li><strong>故事弱點：</strong>揭示您故事中潛在的矛盾或風險，例如「衝擊轉移現象」，這些都是可能被外界質疑為「<span class="highlight-term">漂綠</span>」的地方。</li>
                        </ul>`;
                    strategy = `一個高分的「永續故事」是強大的<span class="highlight-term">品牌資產</span>與市場區隔工具。反之，一個低分或充滿矛盾的故事，在溝通上則存在高風險。此計分卡幫助您在行銷宣傳前，先進行內部的壓力測試。`;
                    advice = `請將「<span class="highlight-term">故事優勢</span>」作為您行銷內容的核心。對於「<span class="highlight-term">故事弱點</span>」，則應返回設計階段，思考如何透過材料或設計變更來解決這些矛盾，從而建立一個更無懈可擊的永續敘事。`;
                    body = build_html(def, insight, strategy, advice);
                    break;

                case 'category-analysis':
                    title = '解讀：物料大類結構分析';
                    let def_cat = `此多層次環狀圖從「<span class="highlight-term">材料家族</span>」的宏觀視角分析您的產品。<b>內圈</b>代表各材料大類（如金屬、塑膠）的<b>重量佔比</b>，<b>外圈</b>則代表它們的<b>碳排貢獻佔比</b>。`;
                    let data_insight_cat = '無法產生動態洞察，數據不完整。';
                    let strat_imp_cat = `此分析幫助您超越單一零組件的侷限，從更高層次的材料組合策略上進行思考。它可以揭示您對某一類高風險、高成本或高碳排材料家族的整體依賴度，是制定長期材料策略的基礎。`;
                    let act_rec_cat = `請優先審視那些<b>外圈佔比遠大於內圈佔比</b>的材料類別。這代表該類別整體屬於「<span class="highlight-term">低重量、高衝擊</span>」的熱點。接著，您可以利用「單件衝擊來源分析」圖表，找出該類別中具體是哪個零組件貢獻了最高的碳排，並對其進行優化。`;

                    try {
                        const categoryData = prepareCategoryData(data.charts.composition);
                        const totalWeight = Object.values(categoryData).reduce((sum, d) => sum + d.weight, 0);
                        const totalCo2 = Object.values(categoryData).reduce((sum, d) => sum + d.co2, 0);

                        if (totalWeight > 0 && totalCo2 > 0) {
                            let max_ratio = 0;
                            let hotspot_category = { name: 'N/A', weight_pct: 0, co2_pct: 0 };
                            for (const categoryName in categoryData) {
                                const weight_pct = (categoryData[categoryName].weight / totalWeight) * 100;
                                const co2_pct = (categoryData[categoryName].co2 / totalCo2) * 100;
                                if (weight_pct > 0.01) {
                                    const ratio = co2_pct / weight_pct;
                                    if (ratio > max_ratio) {
                                        max_ratio = ratio;
                                        hotspot_category = { name: categoryName, weight_pct: weight_pct, co2_pct: co2_pct };
                                    }
                                }
                            }
                            if (hotspot_category.name !== 'N/A' && max_ratio > 1.2) {
                                data_insight_cat = `數據顯示，<b>「${escapeHtml(hotspot_category.name)}」</b>是您產品中<span class="highlight-term">衝擊密度最高</span>的材料類別。儘管它只佔總重量的 <b>${hotspot_category.weight_pct.toFixed(1)}%</b>，卻貢獻了高達 <b>${hotspot_category.co2_pct.toFixed(1)}%</b> 的總碳足跡，其衝擊貢獻是重量貢獻的 <b class="fs-5">${max_ratio.toFixed(1)} 倍</b>。`;
                            } else {
                                data_insight_cat = `您的產品在各材料大類的重量與碳排分佈較為均衡，沒有出現衝擊貢獻與重量貢獻不成比例的極端情況。`;
                            }
                        }
                    } catch (e) {
                        console.error("Error generating category analysis insight:", e);
                    }
                    body = build_html(def_cat, data_insight_cat, strat_imp_cat, act_rec_cat);
                    break;
                case 'waterfall-chart':
                    title = '解讀：碳足跡構成瀑布圖';
                    let def_waterfall = `瀑布圖展示了總碳足跡的「<span class="highlight-term">累積過程</span>」。圖表從左側的 0 開始，每個<b>紅色長條</b>代表一個組件貢獻的碳排放量，使總量增加；每個<b>綠色長條</b>代表一項碳信用或回收效益，使總量減少。最右側的<b>藍色長條</b>則代表最終計算出的總碳足跡。`;
                    let insight_waterfall = `此圖表能讓您直觀地識別出對總碳排貢獻最大的「<span class="highlight-term">正向驅動因子</span>」（最長的紅色長條）以及提供最大減碳效益的「<span class="highlight-term">負向驅動因子</span>」（最長的綠色長條）。`;
                    let strat_waterfall = `此圖表是一種強大的溝通工具，特別適合用來講述您的產品「<span class="highlight-term">減碳故事</span>」。您可以清晰地呈現：“我們的基底碳排是多少，但透過採用XX回收材料（綠色長條），我們成功地將最終碳排降低到了XX”。`;
                    let advice_waterfall = `您的優化重點應放在縮短圖表中最長的幾個紅色長條。同時，思考如何能引入或增加綠色長條的長度，例如提高產品回收率或選用具有更高回收效益的材料。`;
                    body = build_html(def_waterfall, insight_waterfall, strat_waterfall, advice_waterfall);
                    break;

                case 'holistic-analysis':
                    title = '解讀：綜合分析與建議';
                    let formula_holistic = `氣候領導力 = 氣候行動分數
循環實踐力 = 綜合循環經濟分數
資源管理力 = (水資源管理分數 + 資源消耗分數) / 2
衝擊減緩力 = (污染防治分數 + 自然資本分數) / 2`;
                    let def_holistic = `此模組是您的「<span class="highlight-term">AI永續顧問</span>」，它將所有量化數據進行整合，提供一個高層次的、策略性的總覽。`;
                    let insight_holistic = `此模組包含三個核心部分：
                        <ul>
                            <li><strong>永續策略四象限 (雷達圖)：</strong>從「氣候領導力」、「循環實踐力」、「資源管理力」與「衝擊減緩力」四個策略支柱，快速評估產品的綜合表現與平衡性。</li>
                            <li><strong>產品定位：</strong>基於您的數據，系統會自動生成多達16種的「<span class="highlight-term">產品永續定位</span>」，提供快速的質化評估。</li>
                            <li><strong>策略性建議：</strong>基於「產品定位」與「環境熱點」，提供具體、可執行的主要改善建議。</li>
                        </ul>`;
                    let strategy_holistic = `此模組的內容是您向主管、客戶或投資人匯報設計成果時最精華的部分，它將複雜的LCA結果轉化為簡潔的<span class="highlight-term">商業語言</span>與策略方向。`;
                    let advice_holistic = `請將此模組視為您的決策起點。從「產品定位」了解現況，透過雷達圖檢視策略平衡性，並依循「策略性建議」規劃下一步的優化行動。`;
                    body = build_html(def_holistic, insight_holistic, strategy_holistic, advice_holistic, formula_holistic);
                    break;

                case 'impact-matrix':
                    title = '解讀：衝擊密度 vs 重量分佈矩陣圖';
                    let def_matrix = `此圖是一個專業的<span class="highlight-term">生態設計策略工具</span>。它將所有組件分佈在一個二維矩陣中：<b>X軸</b>代表組件佔產品的<b>重量百分比</b>，<b>Y軸</b>代表材料本身的「<span class="highlight-term">衝擊密度</span>」（每公斤的碳排）。氣泡大小則代表該組件的<b>總碳排貢獻</b>。`;
                    let insight_matrix = `透過平均線，圖表被分為四個象限，每個象限的組件都代表了不同的策略意義：
                        <ul>
                            <li><b style="color: rgba(220, 53, 69, 1);">右上 - <span class="highlight-term">主要熱點</span>：</b>高重量、高衝擊密度。這些是您<b>最優先</b>的優化目標。</li>
                            <li><b style="color: rgba(255, 193, 7, 1);">左上 - <span class="highlight-term">隱形殺手</span>：</b>低重量、高衝擊密度。它們雖然不重，但材料本身非常不環保。</li>
                            <li><b style="color: rgba(13, 202, 240, 1);">右下 - <span class="highlight-term">輕量化目標</span>：</b>高重量、低衝擊密度。材料本身不錯，但用量太大。</li>
                            <li><b style="color: rgba(25, 135, 84, 1);">左下 - <span class="highlight-term">次要因子</span>：</b>低重量、低衝擊密度。這些是目前最無須擔心的部分。</li>
                        </ul>`;
                    let strat_matrix = `此矩陣圖是<span class="highlight-term">80/20法則</span>在永續設計上的體現。它幫助您將有限的研發資源，精準地投入到能產生最大環境效益的環節，避免在無關緊要的組件上浪費時間。`;
                    let advice_matrix = `您的優化路徑應為：<b>首先處理「主要熱點」</b>，透過輕量化和材料替換雙管齊下。<b>接著處理「隱形殺手」</b>，尋找低碳的替代材料。<b>最後，對「輕量化目標」</b>進行結構優化以減少用量。`;
                    body = build_html(def_matrix, insight_matrix, strat_matrix, advice_matrix);
                    break;

                case 'impact-indicators':
                    title = '解讀：各項環境衝擊指標';
                    let def_indicators = `此區塊呈現了您產品在多個核心環境衝擊類別上的最終量化結果。這是整個LCA分析中最頂層、最關鍵的<span class="highlight-term">績效指標(KPIs)</span>。`;
                    let insight_indicators = `每個指標下方的「<span class="highlight-term">較原生料節省</span>」數值，是衡量您永續設計效益的<strong>最直接證據</strong>。它代表了您的設計相較於傳統100%使用原生料的設計，所成功避免掉的環境衝擊量。`;
                    let strategy_indicators = `這些KPIs是企業<span class="highlight-term">永續報告(ESG/CSR)</span>中關於產品責任章節的重要數據來源，也是與供應鏈溝通永續要求時的量化基礎。`;
                    let advice_indicators = `當您需要快速評估或報告產品的整體永續表現時，此區塊的數據是您的首要重點。您可以點擊每個指標旁的 <i class="fas fa-comment-dots text-primary opacity-50"></i> 圖示，以獲取更深入的個別解讀。`;
                    body = build_html(def_indicators, insight_indicators, strategy_indicators, advice_indicators);
                    break;

                case 'main-co2':
                    title = '解讀：總碳足跡 (GWP)';
                    let def_co2 = `此數值代表產品在「<span class="highlight-term">搖籃到墳墓</span>」生命週期中所產生的溫室氣體總量，以公斤二氧化碳當量 (kg CO₂e) 表示。`;
                    let insight_co2 = `您產品的總碳足跡為 <strong>${co2_val.toFixed(3)} kg CO₂e</strong>。此數值越低，對氣候的衝擊越小。${(co2_val < -0.001) ? '您的產品實現了「<span class="highlight-term">碳移除</span>」，是淨零排放的典範。' : ''}`;
                    let strategy_co2 = `此指標是貴公司達成<span class="highlight-term">科學基礎減碳目標(SBTi)</span>、實現碳中和承諾、以及在<span class="highlight-term">氣候相關財務揭露(TCFD)</span>中展現韌性的核心量化依據。`;
                    let advice_co2 = `若要降低此數值，您應優先檢視儀表板中的<strong>「環境熱點分析」</strong>，找出貢獻最大的組件進行優化。`;
                    body = build_html(def_co2, insight_co2, strategy_co2, advice_co2, `總碳足跡 = Σ [ (生產碳排) + (廢棄處理碳排) ]`);
                    break;

                case 'main-energy':
                    title = '解讀：總能源消耗 (CED)';
                    let def_energy = `此數值代表在「<span class="highlight-term">搖籃到大門</span>」(Cradle-to-Gate)範疇內，製造產品所需投入的總初級能源，單位為百萬焦耳 (MJ)。它反映了產品的「<span class="highlight-term">隱含能源</span>」(Embodied Energy)。`;
                    let insight_energy = `您產品的總能耗為 <strong>${imp.energy.toFixed(2)} MJ</strong>，相較於原生料基準降低了 <strong>${energy_reduction_pct.toFixed(1)}%</strong>。`;
                    let strategy_energy = `能源消耗與營運成本及地緣政治風險高度相關。降低產品的隱含能源，有助於提升供應鏈的<span class="highlight-term">穩定性</span>與<span class="highlight-term">成本可預測性</span>。`;
                    let advice_energy = `選擇<strong>再生材料</strong>是降低隱含能源最有效的途徑，因其通常能耗遠低於原生材料的開採與提煉。此外，優化製造流程也能帶來顯著節能效益。`;
                    body = build_html(def_energy, insight_energy, strategy_energy, advice_energy);
                    break;

                case 'main-water':
                    title = '解讀：總水資源消耗';
                    let def_water = `此數值代表在「<span class="highlight-term">搖籃到大門</span>」(Cradle-to-Gate)範疇內，製造產品所消耗的總淡水資源，單位為公升 (L)。它反映了產品的「<span class="highlight-term">水足跡</span>」(Water Footprint)中的直接消耗部分。`;
                    let insight_water = `您產品的總水耗為 <strong>${imp.water.toFixed(2)} L</strong>，相較於原生料基準節省了 <strong>${water_reduction_pct.toFixed(1)}%</strong>。`;
                    let strategy_water = `在水資源日益稀缺的地區，產品水足跡是衡量企業<span class="highlight-term">營運風險</span>與<span class="highlight-term">社會責任</span>的關鍵指標。低水足跡產品更具備供應鏈韌性與市場好感度。`;
                    let advice_water = `特別是針對棉花、皮革等高水耗的天然材料，或需要大量用水的濕式製程，<strong>採用再生替代方案或改用乾式製程</strong>能帶來最顯著的節水效益。`;
                    body = build_html(def_water, insight_water, strategy_water, advice_water);
                    break;
                case 'lifecycle':
                    title = '解讀：生命週期階段分析';
                    let def_lifecycle = `此圖將總碳足跡拆解為「<span class="highlight-term">原料生產製造</span>」(上游)與「<span class="highlight-term">廢棄處理</span>」(下游)兩個主要階段。`;
                    const prod_co2 = data.charts.lifecycle_co2.production;
                    let insight_lifecycle = `數據顯示，「原料生產製造」是您產品碳足跡的最主要來源。這意味著您的產品是一個典型的「<span class="highlight-term">上游主導型</span>」產品，其絕大部分的環境衝擊在產品離開工廠大門前就已決定。`;
                    let strategy_lifecycle = `您的永續策略應聚焦於<strong>上游 (Upstream)</strong>，包括：1) 提高再生材料比例；2) 選擇本身碳足跡更低的替代材料；3) 導入<span class="highlight-term">輕量化設計</span>以減少材料總量。`;
                    body = build_html(def_lifecycle, insight_lifecycle, strategy_lifecycle, '');
                    break;

                case 'content':
                    title = '解讀：循環性指數';
                    let def_content = `此圖分析產品中「原生材料」與「再生材料」的重量佔比，是評估產品<span class="highlight-term">循環經濟度</span>的關鍵指標。`;
                    const recycled_pct = data.charts.content_by_type.recycled / data.inputs.totalWeight * 100;
                    let insight_content = `您產品的再生料總佔比(循環性指數)為 <strong>${recycled_pct.toFixed(1)}%</strong>。`;
                    let strategy_content = `此指標直接回應了全球對<span class="highlight-term">循環經濟</span>的政策要求（如歐盟新循環經濟行動計畫）。高循環性指數不僅降低了對原生資源的依賴與價格波動風險，更是進入「<span class="highlight-term">綠色供應鏈</span>」與滿足永續採購要求的入場券。`;
                    let advice_content = `持續最大化此比例，並確保所用的再生料具備優良的減碳效益，以提升整體的「<span class="highlight-term">材料效率</span>」。`;
                    body = build_html(def_content, insight_content, strategy_content, advice_content);
                    break;

                case 'composition':
                    title = '解讀：質量貢獻分析';
                    let def_composition = `此圖展示了構成產品的各個組件及其重量分佈。`;
                    const heaviest = [...data.charts.composition].sort((a, b) => b.weight - a.weight)[0];
                    let insight_composition = `數據顯示，<strong>${escapeHtml(heaviest.name)}</strong> 是產品中<span class="highlight-term">質量貢獻最大</span>的組件，佔了總重量的 <strong>${(heaviest.weight / data.inputs.totalWeight * 100).toFixed(0)}%</strong>。`;
                    let strategy_composition = `組件的質量貢獻不僅影響材料成本，更直接關聯到運輸階段的碳排放（<span class="highlight-term">範疇三排放</span>）。`;
                    let advice_composition = `針對最重組件的「<span class="highlight-term">輕量化</span>」(Lightweighting)設計，是兼具環境與商業效益的經典生態化設計策略。可從材料替換（如以塑換鋼）或結構拓撲優化等方向著手。`;
                    body = build_html(def_composition, insight_composition, strategy_composition, advice_composition);
                    break;

                case 'impact-source':
                    title = '解讀：環境熱點分析';
                    let def_impact_source = `此圖揭示了造成各項環境衝擊的<span class="highlight-term">主要來源材料</span>。`;
                    const hotspot = [...data.charts.composition].sort((a,b)=>b.co2 - a.co2)[0];
                    let insight_impact_source = `數據清楚地指出，<strong>${escapeHtml(hotspot.name)}</strong> 是您產品最主要的「<span class="highlight-term">碳排熱點</span>」，貢獻了總碳足跡的 <strong>${(hotspot.co2 / data.impact.co2 * 100).toFixed(0)}%</strong>。`;
                    let strategy_impact_source = `熱點分析是LCA中最具<span class="highlight-term">成本效益</span>的決策工具，它將有限的研發資源聚焦於能產生最大環境效益的環節，是實現「<span class="highlight-term">生態效益</span>」(Eco-efficiency)最大化的關鍵路徑。`;
                    let advice_impact_source = `您的所有優化資源與時間，都應<strong>優先投入</strong>在此圖表所揭示的1-2個關鍵熱點上，方能取得最高效益的改善。`;
                    body = build_html(def_impact_source, insight_impact_source, strategy_impact_source, advice_impact_source);
                    break;

                case 'impact-savings':
                    title = '解讀：減碳效益分析';
                    let def_impact_savings = `此圖表量化了因使用再生材料而帶來的<span class="highlight-term">減碳效益</span>。`;
                    const top_saver = Object.entries(data.charts.savings_by_material).map(([name, values]) => ({name, ...values})).sort((a,b) => b.co2_saved - a.co2_saved)[0];
                    let insight_impact_savings;
                    if(top_saver && top_saver.co2_saved > 0){
                        insight_impact_savings = `數據顯示，<strong>${escapeHtml(top_saver.name)}</strong> 是您的「<span class="highlight-term">減碳效益冠軍</span>」，貢獻了最大的減碳量。`;
                    } else {
                        insight_impact_savings = `目前您的設計尚未透過使用再生料產生顯著的減碳效益。`;
                    }
                    let strategy_impact_savings = `此圖表可視為您的<strong>「<span class="highlight-term">永續設計投資報酬率(ROI)分析</span>」</strong>，用於評估不同再生材料的減碳效率，並證明永續材料投資的合理性。`;
                    let advice_impact_savings = `將設計資源與材料採購，優先集中在那些能產生最大「已節省碳排」(綠色長條)的材料上。`;
                    body = build_html(def_impact_savings, insight_impact_savings, strategy_impact_savings, advice_impact_savings);
                    break;

                case 'impact-comparison':
                    title = '解讀：標竿分析';
                    let def_impact_comparison = `此圖表將您的產品與一個「<span class="highlight-term">100%原生料</span>」的產業基準情境進行直接比較。`;
                    let insight_impact_comparison = `數據顯示，與傳統設計相比，您的產品成功將碳足跡降低了 <strong>${co2_reduction_pct.toFixed(1)}%</strong>。`;
                    let strategy_impact_comparison = `此標竿分析不僅是內部績效評估的工具，更是建構產品「<span class="highlight-term">綠色溢價</span>」(Green Premium)與市場差異化策略的基礎。此百分比可作為量化證據，用於抵禦「<span class="highlight-term">漂綠</span>」(Greenwashing)指控。`;
                    let advice_impact_comparison = `持續擴大此百分比差距，是強化您產品綠色競爭力的關鍵。當此數值為負時，代表您的設計在環保表現上劣於產業平均，需立即採取行動。`;
                    body = build_html(def_impact_comparison, insight_impact_comparison, strategy_impact_comparison, advice_impact_comparison);
                    break;

                case 'equivalents':
                    title = '解讀：效益故事化模組';
                    let def_equivalents = `此區塊將抽象的環境衝擊節省量，轉換為日常生活中更容易感受的具體活動。`;
                    let insight_equivalents = `您的設計所節省的環境衝擊，約當於您在此看到的各項生活指標。`;
                    let strategy_equivalents = `永續設計的成功不僅在於技術優化，更在於有效的「<span class="highlight-term">利害關係人溝通</span>」。此模組是將複雜LCA數據進行「<span class="highlight-term">故事化轉譯</span>」的關鍵工具。`;
                    let advice_equivalents = `善用這些具象化的比喻，能讓您的永續成果在面對消費者、投資人、行銷團隊或內部非技術背景的主管時，更具穿透力與說服力。`;
                    body = build_html(def_equivalents, insight_equivalents, strategy_equivalents, advice_equivalents);
                    break;

                case 'radar-chart-specific':
                    title = '解讀：永續策略四象限雷達圖';
                    let def_radar = `此雷達圖是您產品的「<span class="highlight-term">永續策略儀表板</span>」。它將複雜的環境績效數據，整合為四個互相獨立的策略支柱，幫助您快速診斷產品的優勢與短版。分數越高（越靠近外圈），代表在該策略支柱的表現越好。`;
                    let insight_radar = `各策略支柱的定義如下：
                        <ul>
                            <li><b>氣候領導力：</b>直接反映產品的「<span class="highlight-term">減碳成效</span>」。此分數越高，代表產品在應對氣候變遷、達成淨零目標上的領導地位越強。</li>
                            <li><b>循環實踐力：</b>綜合評估產品在「<span class="highlight-term">循環經濟</span>」上的實踐程度，包含MCI指數、廢棄物管理與資源消耗效率。</li>
                            <li><b>資源管理力：</b>衡量產品對<span class="highlight-term">水資源</span>與<span class="highlight-term">稀缺礦物資源</span>的綜合管理能力。分數越高，代表供應鏈對這兩類有限資源的依賴度越低，韌性越高。</li>
                            <li><b>衝擊減緩力：</b>評估產品在降低「<span class="highlight-term">污染</span>」（如酸化、優養化）與保護「<span class="highlight-term">自然資本</span>」（如生物多樣性）等負面外部性方面的成效。</li>
                        </ul>`;
                    let strat_radar = `一張均衡且飽滿的雷達圖，代表一個穩健、全面的永續設計。反之，若圖形在某個象限有明顯的內縮，則清楚地指出了您的「<span class="highlight-term">策略短版</span>」，以及最需要投入資源進行改善的方向。`;
                    body = build_html(def_radar, insight_radar, strat_radar, '');
                    break;
                case 'deep-dive-macro':
                    title = '解讀：宏觀掃描 (解構材料基因)';
                    let def_macro = `此步驟從最高層次的「<span class="highlight-term">材料家族</span>」視角來分析您的產品，回答一個核心問題：「我的產品，本質上是由什麼構成的？」。多層次環狀圖的<b>內圈</b>代表各材料大類（如金屬、塑膠）的<b>重量佔比</b>，<b>外圈</b>則代表它們的<b>碳排貢獻佔比</b>。`;
                    let insight_macro = `關鍵洞察來自比較內外圈的比例。如果某個材料家族的「外圈佔比」遠大於「內圈佔比」，這意味著該材料家族屬於「<span class="highlight-term">低重量、高衝擊</span>」類型，是影響產品永續表現的「<span class="highlight-term">高密度</span>」因子。`;
                    let strat_macro = `此宏觀分析幫助您超越單一零組件的侷限，思考整體的<span class="highlight-term">材料組合策略</span>。它能揭示您對某一類高風險、高成本或高碳排材料家族的系統性依賴，是制定長期材料策略、<span class="highlight-term">分散供應鏈風險</span>的基礎。`;
                    let advice_macro = `您的行動路徑非常清晰：首先找出外圈佔比最不成比例的材料類別，然後進入下一步「精準定位」，從該類別中找出具體是哪個零組件貢獻了最高的碳排，並對其進行優化。`;
                    body = build_html(def_macro, insight_macro, strat_macro, advice_macro);
                    break;

                case 'deep-dive-hotspot':
                    title = '解讀：精準定位 (鎖定衝擊熱點)';
                    let def_hotspot = `此矩陣圖是一個專業的<span class="highlight-term">生態設計策略工具</span>，它將所有組件分佈在一個二維矩陣中：<b>X軸</b>是組件的<b>重量佔比</b>，<b>Y軸</b>是材料本身的「<span class="highlight-term">衝擊密度</span>」（每公斤碳排）。氣泡大小則代表該組件的<b>總碳排貢獻</b>。這是將「<span class="highlight-term">帕雷托80/20法則</span>」應用於永續設計的利器。`;
                    let insight_hotspot = `圖表被平均線分為四個象限，各有不同的策略意義：
                        <ul>
                            <li><b style="color: rgba(220, 53, 69, 1);">右上 - <span class="highlight-term">主要熱點</span>：</b>高重量、高衝擊。這是您<b>最優先</b>的優化目標。</li>
                            <li><b style="color: rgba(255, 193, 7, 1);">左上 - <span class="highlight-term">隱形殺手</span>：</b>低重量、高衝擊。它們雖輕，但材料本身極不環保，常在成本分析中被忽略。</li>
                            <li><b style="color: rgba(13, 202, 240, 1);">右下 - <span class="highlight-term">輕量化目標</span>：</b>高重量、低衝擊。材料本身不錯，但用量過大，有減重潛力。</li>
                            <li><b style="color: rgba(25, 135, 84, 1);">左下 - <span class="highlight-term">次要因子</span>：</b>低重量、低衝擊。這些是目前最無須擔心的部分。</li>
                        </ul>`;
                    let strat_hotspot = `此分析的策略價值在於「<span class="highlight-term">聚焦</span>」。它幫助您將有限的研發資源，精準地投入到能產生最大環境效益的環節，避免在無關緊要的組件上浪費時間與金錢。`;
                    let advice_hotspot = `您的優化路徑應為：<b>首先處理「主要熱點」</b>；<b>接著處理「隱形殺手」</b>（通常是高ROI的替換點）；<b>最後，對「輕量化目標」</b>進行結構優化。`;
                    body = build_html(def_hotspot, insight_hotspot, strat_hotspot, advice_hotspot);
                    break;

                case 'deep-dive-pathway':
                    title = '解讀：路徑追溯 (量化減碳貢獻)';
                    let def_pathway = `瀑布圖展示了總碳足跡的「<span class="highlight-term">累積過程與故事</span>」。圖表從左側的0開始，每個<b>紅色長條</b>代表一個組件貢獻的「碳排放」，使總量增加；每個<b>綠色長條</b>代表一項「碳信用」或「回收效益」，使總量減少。最右側的長條則代表最終計算出的淨總碳足跡。`;
                    let insight_pathway = `此圖表能讓您直觀地識別出對總碳排貢獻最大的「<span class="highlight-term">正向驅動因子</span>」（最長的紅色長條）以及提供最大減碳效益的「<span class="highlight-term">負向驅動因子</span>」（最長的綠色長條）。它揭示了產品內部的「<span class="highlight-term">英雄</span>」與「<span class="highlight-term">反派</span>」。`;
                    let strat_pathway = `這是一項強大的「<span class="highlight-term">永續故事溝通</span>」工具。您可以清晰地呈現：“我們的基底碳排是多少，但透過採用XX回收材料（綠色長條），我們成功地將最終碳排降低到了XX”。這為您的減碳努力提供了量化證據。`;
                    let advice_pathway = `您的優化策略應雙管齊下：1. <b>削弱反派：</b>盡力縮短圖表中最長的幾個紅色長條。2. <b>強化英雄：</b>思考如何能引入或增加綠色長條的長度，例如提高產品回收率或選用具有更高回收效益的材料。`;
                    body = build_html(def_pathway, insight_pathway, strat_pathway, advice_pathway);
                    break;

                case 'cost-carbon-matrix':
                    title = '解讀：成本 vs. 碳排 矩陣圖';
                    let def_cost_matrix = `此圖是一個關鍵的<span class="highlight-term">商業決策工具</span>，它同時呈現了每個組件的<b>環境衝擊（X軸：碳足跡貢獻）</b>與<b>財務衝擊（Y軸：成本貢獻）</b>。氣泡的大小則代表該組件的<b>重量</b>。`;
                    let insight_cost_matrix = `此圖表幫助您同時識別出財務與環境上的雙重熱點，並將組件歸類於四個策略象限：
                        <ul>
                            <li><b>右上 - <span class="highlight-term">雙重熱點 (Win-Win)</span>：</b>高成本、高碳排。這是<b>最優先</b>的優化目標，任何改善都能同時帶來財務和環境效益。</li>
                            <li><b>右下 - <span class="highlight-term">隱藏的環境成本</span>：</b>低成本、高碳排。這些是「便宜但骯髒」的零件，是展現企業社會責任、尋求低碳替代品的絕佳機會點。</li>
                            <li><b>左上 - <span class="highlight-term">財務壓力點</span>：</b>高成本、低碳排。這些是「昂貴的綠色選擇」。</li>
                            <li><b>左下 - <span class="highlight-term">最佳實踐區</span>：</b>低成本、低碳排。這些是您產品中的模範生。</li>
                        </ul>`;
                    let strat_cost_matrix = `此圖表是永續團隊與採購/財務團隊之間最佳的<span class="highlight-term">溝通橋樑</span>。它將「永續性」這個抽象概念，轉化為與「成本」直接相關的具體數據，有力地證明了「綠色設計」同樣可以是「經濟的設計」。`;
                    let advice_cost_matrix = `您的行動路徑應為：1. 集中資源處理<b>「雙重熱點」</b>，尋求更便宜且更低碳的方案。 2. 針對<b>「隱藏的環境成本」</b>，評估導入成本稍高但碳排顯著降低的替代材料。 3. 對於<b>「財務壓力點」</b>，則應尋求降低成本的途徑，如尋找替代供應商或優化製程。`;
                    body = build_html(def_cost_matrix, insight_cost_matrix, strat_cost_matrix, advice_cost_matrix);
                    break;

                case 'cost-deep-dive-macro':
                    title = '解讀：第一幕 - 宏觀掃描 (鳥瞰成本結構)';
                    let def_cost_macro = `此環圈圖呈現了產品總材料成本的組成結構，回答一個核心問題：「錢主要花在哪裡？」。圖中每個區塊的大小，代表該組件佔總成本的百分比。`;
                    let insight_cost_macro = `透過此圖，您可以快速識別出對總成本貢獻最大的1-2個「<span class="highlight-term">財務熱點</span>」(Financial Hotspot)。理解成本是集中在少數關鍵零件，還是平均分佈在多個零件中，是制定成本優化策略的第一步。`;
                    let strat_cost_macro = `此分析是連結採購、設計與財務部門的<span class="highlight-term">共同語言</span>。它將抽象的BOM表轉化為直觀的成本分佈，讓團隊能聚焦在刀口上，針對最高成本的組件進行<span class="highlight-term">價值工程分析(Value Engineering)</span>或供應商議價。`;
                    let advice_cost_macro = `請優先關注佔比最高的組件。如果成本高度集中（例如，單一組件佔比 > 50%），您的首要任務就是為這個財務熱點尋找降本方案。如果成本分佈較均勻，則可進入下一幕，分析這些成本與環境衝擊的關聯。`;
                    body = build_html(def_cost_macro, insight_cost_macro, strat_cost_macro, advice_cost_macro);
                    break;

                case 'cost-deep-dive-positioning':
                    title = '解讀：第二幕 - 精準定位 (連結成本與碳排)';
                    let def_cost_pos = `此矩陣圖是一個關鍵的<span class="highlight-term">商業決策工具</span>，它同時呈現了每個組件的<b>環境衝擊（X軸：碳足跡貢獻）</b>與<b>財務衝擊（Y軸：成本貢獻）</b>。氣泡的大小則代表該組件的<b>重量</b>。`;
                    let insight_cost_pos = `此圖表幫助您同時識別出財務與環境上的雙重熱點，並將組件歸類於四個策略象限：
                        <ul>
                            <li><b>右上 - <span class="highlight-term">雙重熱點 (Win-Win)</span>：</b>高成本、高碳排。這是<b>最優先</b>的優化目標，任何改善都能同時帶來財務和環境效益。</li>
                            <li><b>右下 - <span class="highlight-term">隱藏的環境成本</span>：</b>低成本、高碳排。這些是「便宜但骯髒」的零件，是展現企業社會責任、尋求低碳替代品的絕佳機會點。</li>
                            <li><b>左上 - <span class="highlight-term">財務壓力點</span>：</b>高成本、低碳排。這些是「昂貴的綠色選擇」。</li>
                            <li><b>左下 - <span class="highlight-term">最佳實踐區</span>：</b>低成本、低碳排。這些是您產品中的模範生。</li>
                        </ul>`;
                    let strat_cost_pos = `此圖表是永續團隊與採購/財務團隊之間最佳的<span class="highlight-term">溝通橋樑</span>。它將「永續性」這個抽象概念，轉化為與「成本」直接相關的具體數據，有力地證明了「綠色設計」同樣可以是「經濟的設計」。`;
                    let advice_cost_pos = `您的行動路徑應為：1. 集中資源處理<b>「雙重熱點」</b>，尋求更便宜且更低碳的方案。 2. 針對<b>「隱藏的環境成本」</b>，評估導入成本稍高但碳排顯著降低的替代材料。 3. 對於<b>「財務壓力點」</b>，則應尋求降低成本的途徑，如尋找替代供應商或優化製程。`;
                    body = build_html(def_cost_pos, insight_cost_pos, strat_cost_pos, advice_cost_pos);
                    break;

                case 'cost-deep-dive-comparison':
                    title = '解讀：第三幕 - 效益分析 (量化綠色ROI)';
                    let def_cost_comp = `此圖表透過將您「當前設計」的成本與一個假設「100%使用原生材料」的基準設計成本進行比較，直接量化了您永續策略的<span class="highlight-term">財務成果</span>。`;
                    let insight_cost_comp = `圖表的最終結果只有兩種可能：
                        <ul>
                            <li><b>您的成本更低：</b>恭喜！這代表您的永續策略（如使用再生料）產生了「<span class="highlight-term">綠色折扣</span>」(Green Discount)，不僅對環境友善，還能節省成本。</li>
                            <li><b>您的成本更高：</b>這代表您為了永續性付出了「<span class="highlight-term">綠色溢價</span>」(Green Premium)。這個溢價是您為品牌價值、法規遵循和企業社會責任所做的具體投資。</li>
                        </ul>`;
                    let strat_cost_comp = `這個量化結果是您向管理層、投資人或客戶溝通永續價值時最強大的數據。它將「<span class="highlight-term">做好事</span>」轉化為「<span class="highlight-term">聰明的生意</span>」，或者清晰地定義了「做好事」的價格，為產品定價和行銷提供了堅實依據。`;
                    let advice_cost_comp = `如果結果是「綠色折扣」，請將此作為關鍵行銷亮點。如果結果是「綠色溢價」，您的任務是向市場溝通這個溢價背後的價值，並持續與供應鏈合作，尋求降低這個溢價的機會。`;
                    body = build_html(def_cost_comp, insight_cost_comp, strat_cost_comp, advice_cost_comp);
                    break;

                case 'main-acidification':
                    title = '解讀：酸化潛力 (AP)';
                    let def_ap = `此數值衡量產品生命週期中，會導致「<span class="highlight-term">酸雨</span>」的污染物（如硫氧化物 SOx、氮氧化物 NOx）的總排放潛力，單位為公斤二氧化硫當量 (kg SO₂e)。`;
                    let insight_ap = `您產品的酸化潛力為 <strong>${imp.acidification.toExponential(3)} kg SO₂e</strong>。此數值主要來自化石燃料燃燒（能源消耗、運輸）以及特定工業製程。`;
                    let strat_ap = `高酸化潛力會對生態系統（土壤、水源）造成破壞，並損害建築物。在許多國家，SOx與NOx的排放受到嚴格的空氣品質法規管制，是企業面臨的<span class="highlight-term">合規風險</span>之一。`;
                    let advice_ap = `若要降低此數值，請優先檢視：1. <b>供應鏈的能源結構</b>：鼓勵供應商採用再生能源。2. <b>運輸方式</b>：選擇更潔淨的運輸工具。3. <b>材料選擇</b>：某些金屬冶煉或化工製程有較高的SOx/NOx排放，可評估替代方案。`;
                    body = build_html(def_ap, insight_ap, strat_ap, advice_ap);
                    break;

                case 'main-eutrophication':
                    title = '解讀：優養化潛力 (EP)';
                    let def_ep = `此數值衡量產品生命週期中，會導致水體「<span class="highlight-term">優養化</span>」（如藻類過度繁殖、水中缺氧）的營養鹽（如氮、磷化合物）的總排放潛力，單位為公斤磷酸鹽當量 (kg PO₄e)。`;
                    let insight_ep = `您產品的優養化潛力為 <strong>${imp.eutrophication.toExponential(3)} kg PO₄e</strong>。此衝擊主要與農業活動（用於棉花、生質塑膠等原料）的肥料流失，以及工業廢水排放有關。`;
                    let strat_ep = `水體優養化是全球性的水污染問題，影響生態平衡與用水安全。高優養化潛力的產品，可能在供應鏈的<span class="highlight-term">永續性稽核</span>中被視為高風險。`;
                    let advice_ep = `若要降低此數值，請優先檢視：1. <b>天然纖維/生質原料來源</b>：選擇來自永續農業或有機農法的原料。2. <b>濕式製程</b>：確保供應鏈中的染色、電鍍等工廠有完善的廢水處理設施。`;
                    body = build_html(def_ep, insight_ep, strat_ep, advice_ep);
                    break;

                case 'main-ozone':
                    title = '解讀：臭氧層破壞潛力 (ODP)';
                    let def_odp = `此數值衡量產品生命週期中，所排放的破壞平流層（同溫層）臭氧的化學物質（如氟氯碳化物 CFCs）的總潛力，單位為公斤CFC-11當量 (kg CFC-11e)。`;
                    let insight_odp = `您產品的臭氧層破壞潛力為 <strong>${imp.ozone_depletion.toExponential(3)} kg CFC-11e</strong>。此衝擊在現代產品中通常很低，主要與舊式的冷媒、發泡劑或特定溶劑有關。`;
                    let strat_odp = `臭氧層破壞是一個受到《<span class="highlight-term">蒙特婁議定書</span>》嚴格國際公約管制的議題。任何ODP數值的顯著存在都可能意味著供應鏈中存在違規使用禁用物質的嚴重風險，可能導致貿易限制。`;
                    let advice_odp = `雖然數值通常很低，但仍建議進行「<span class="highlight-term">盡職調查</span>」(Due Diligence)，確保供應鏈中（特別是發泡材料、冷凍設備）沒有使用任何已被淘汰的ODP物質。此指標更偏向「<span class="highlight-term">風險控管</span>」而非「優化」。`;
                    body = build_html(def_odp, insight_odp, strat_odp, advice_odp);
                    break;
                case 'main-smog':
                    title = '解讀：光化學臭氧生成潛力 (POCP)';
                    let def_pocp = `也稱為「<span class="highlight-term">光化學煙霧</span>」。此數值衡量產品生命週期中，所排放的揮發性有機化合物（VOCs）和氮氧化物（NOx）在陽光照射下，於對流層（地面）形成臭氧（一種空氣污染物）的潛力，單位為公斤乙烯當量 (kg NMVOCe)。`;
                    let insight_pocp = `您產品的光化學臭氧生成潛力為 <strong>${imp.photochemical_ozone.toExponential(3)} kg NMVOCe</strong>。此衝擊主要來自溶劑、塗料、油墨、黏著劑的揮發，以及運輸過程中的廢氣排放。`;
                    let strat_pocp = `地面臭氧是造成空氣品質惡化、影響人體健康的主要污染物之一。許多地區對VOCs的排放有嚴格的工業安全與環保法規。降低此數值有助於提升產品的<span class="highlight-term">健康與安全形象</span>。`;
                    let advice_pocp = `若要降低此數值，請優先評估：1. <b>塗料與黏著劑</b>：改用低VOC或水性產品。2. <b>印刷製程</b>：採用環保油墨。3. <b>運輸與能源</b>：與酸化潛力的改善路徑相似，優化運輸與能源結構。`;
                    body = build_html(def_pocp, insight_pocp, strat_pocp, advice_pocp);
                    break;

                case 'main-adp':
                    title = '解讀：資源消耗潛力 (ADP)';
                    let def_adp = `此數值衡量產品生命週期中，對「<span class="highlight-term">非再生資源</span>」（如礦物、金屬、化石燃料）的消耗程度，單位為公斤銻當量 (kg Sb eq)。數值越低，代表對稀缺資源的依賴與消耗越小。`;
                    const adp_val = imp.adp;
                    const v_adp_val = v_imp.adp;
                    const adp_reduction_pct = v_adp_val > 0 ? ((v_adp_val - adp_val) / v_adp_val * 100) : 0;
                    let insight_adp = `您產品的資源消耗潛力為 <strong>${adp_val.toExponential(2)} kg Sb eq</strong>。與100%原生料基準相比，您的設計成功降低了 <strong>${adp_reduction_pct.toFixed(1)}%</strong> 的非再生資源消耗。`;
                    let strategy_adp = `此指標是評估產品「<span class="highlight-term">循環經濟</span>」程度的進階指標，直接關係到供應鏈的<span class="highlight-term">長期韌性</span>與對地球資源的責任。降低對稀缺資源的依賴，是企業永續經營的關鍵策略。`;
                    let advice_adp = `若要進一步降低此數值，您的策略應聚焦於：1. <b>最大化再生材料使用率</b>，特別是金屬與塑膠。2. 針對高ADP貢獻的組件，探索使用「<span class="highlight-term">生物基材料</span>」作為替代方案的可能性。`;
                    body = build_html(def_adp, insight_adp, strategy_adp, advice_adp);
                    break;

                case 'resilience-deep-dive':
                    title = '解讀：環境指紋深度剖析模組';
                    let def_resilience = `此進階模組旨在回答一個核心問題：「在追求『低碳』的同時，我們是否不經意地在其他方面（如酸化、水污染）製造了新的、更嚴重的問題？」。它幫助您超越單一指標的侷限，審視產品整體的<span class="highlight-term">環境穩健性</span>與潛在風險。`;
                    let insight_resilience = `此模組透過「三幕劇」的分析流程，引導您從更高維度的視角進行決策：
                        <ul>
                            <li><b>第一幕 (整體診斷)：</b>透過「環境指紋雷達圖」，快速識別出您產品在哪個環境構面表現最弱，找出潛在的「<span class="highlight-term">短板</span>」。</li>
                            <li><b>第二幕 (根本原因探勘)：</b>利用「堆疊式熱點分析圖」，精準定位造成該「短板」問題的元凶是哪個物料組件。</li>
                            <li><b>第三幕 (權衡與決策)：</b>在您試圖修復這個「短板」問題前，透過「權衡矩陣圖」評估您的解決方案是否會對主要目標（通常是碳足跡）產生負面影響，以避免「<span class="highlight-term">衝擊轉移</span>」。</li>
                        </ul>`;
                    let strat_resilience = `在永續報告與對外溝通中，能夠證明您不僅僅關注碳排，而是對<span class="highlight-term">多重環境衝擊</span>進行了系統性管理與權衡，是專業與領導力的體現，能有效應對利害關係人對於「衝擊轉移」的質疑。`;
                    let advice_resilience = `請將此模組作為您進行「<span class="highlight-term">綠色設計變更</span>」前的標準檢查流程。它能幫助您做出更全面、更穩健、風險更低的永續設計決策。`;
                    body = build_html(def_resilience, insight_resilience, strat_resilience, advice_resilience);
                    break;

                case 'resilience-act1':case 'resilience-act1':
                    title = '解讀：第一幕 - 整體診斷';
                    let formula_resilience1 = `單項改善分數 = ( (原生料衝擊 - 當前衝擊) / |原生料衝擊| ) × 100
畫像診斷依據：所有單項分數的「平均值」與「標準差」`;
                    let def_act1 = `此步驟透過「環境指紋雷達圖」，將您的產品在七個不同環境衝擊類別上的「改善表現」進行標準化呈現。圖形越飽滿、越靠近外圈，代表您的設計相較於100%原生料基準，在該項目的改善幅度越大、表現越好。`;
                    let insight_act1 = `此圖的關鍵洞察在於快速識別「<span class="highlight-term">短板</span>」。圖形中最靠近中心點（或得分最低）的那個角，就是您產品當前最薄弱的環境環節，也是潛在的聲譽或法規風險所在。`;
                    let strat_act1 = `一個均衡的、飽滿的雷達圖代表一個穩健、全面的永續設計。一個畸形的、內縮的圖則代表了需要立即關注的「<span class="highlight-term">衝擊轉移</span>」風險。`;
                    let advice_act1 = `請找出得分最低的那個項目，記住它的名稱，然後進入「第二幕」，我們將探究是哪個零件導致了這個弱點。`;
                    body = build_html(def_act1, insight_act1, strat_act1, advice_act1, formula_resilience1);
                    break;

                case 'resilience-act2':
                    title = '解讀：第二幕 - 根本原因探勘';
                    let def_act2 = `此「堆疊式熱點分析圖」是<span class="highlight-term">根本原因探勘</span>（Root Cause Analysis）的利器。它將造成各類環境問題的「元凶」（物料組件）進行量化與視覺化。`;
                    let insight_act2 = `每一根垂直長條代表一項環境問題。長條中，佔據面積最大的那個色塊，就是造成該問題的<span class="highlight-term">最主要貢獻者</span>。您可以輕易地比較不同問題背後的主因是否為同一個零件。`;
                    let strat_act2 = `此分析能防止您「頭痛醫頭、腳痛醫腳」。例如，您可能會發現「鋁合金」同時是酸化和能源消耗問題的元兇，這意味著替換它將可能帶來「<span class="highlight-term">一石二鳥</span>」的效益。`;
                    let advice_act2 = `請聚焦於您在第一幕中發現的「短板」問題所對應的那根長條，找出其中最大的色塊。這個物料就是您在第三幕中需要進行「<span class="highlight-term">權衡分析</span>」的主角。`;
                    body = build_html(def_act2, insight_act2, strat_act2, advice_act2);
                    break;

                case 'resilience-act3':
                    title = '解讀：第三幕 - 權衡與決策';
                    let def_act3 = `此「權衡矩陣圖」是進行設計變更前，評估潛在「<span class="highlight-term">副作用</span>」的專業工具，旨在避免「<span class="highlight-term">衝擊轉移</span>」（即為了解決A問題，卻導致B問題惡化）。`;
                    let insight_act3 = `圖表將您在第二幕中鎖定的「問題零件」，放到一個矩陣中進行評估：
                        <ul>
                            <li><b>X軸：</b>此零件在「短板問題」（如：酸化）上的衝擊貢獻。</li>
                            <li><b>Y軸：</b>此零件在「主要目標」（通常是碳足跡）上的衝擊貢獻。</li>
                        </ul>
                        如果一個零件在兩個軸上都表現很高（位於右上角），代表優化它是一個「<span class="highlight-term">雙贏</span>」的機會。如果它在X軸很高，但在Y軸很低，代表您可以較無顧慮地替換它，而不用擔心會惡化總碳足跡。`;
                    let strat_act3 = `專業的LCA從業者與永續設計師，其價值不僅在於「找到問題」，更在於「提出<span class="highlight-term">不會產生副作用的解決方案</span>」。此權衡分析就是專業性的體現。`;
                    let advice_act3 = `根據此圖的分析結果，做出最終決策：如果優化此零件是「雙贏」或「低風險」的，則應將其列為高優先級行動方案。如果存在顯著的權衡關係，則需要更謹慎地評估替代方案，或重新審視您的主要目標。`;
                    body = build_html(def_act3, insight_act3, strat_act3, advice_act3);
                    break;

                case 'main-cost':
                    title = '解讀：材料總成本';
                    let def_cost = `此數值代表根據您在左側為每個組件輸入的「單位成本」所計算出的產品材料總成本（<span class="highlight-term">Cost of Goods Sold - Materials</span>）。這是一個「自下而上」(Bottom-up)的成本估算。`;
                    let insight_cost = `您設計的產品材料總成本為 <strong>${imp.cost.toLocaleString(undefined, {maximumFractionDigits: 2})} 元</strong>。`;
                    if (data.virgin_impact && data.virgin_impact.cost > 0) {
                        const cost_diff_pct = ((imp.cost - v_imp.cost) / v_imp.cost) * 100;
                        if (cost_diff_pct > 0) {
                            insight_cost += ` 這比使用100%資料庫原生料的基準成本高出 <strong>${cost_diff_pct.toFixed(1)}%</strong>，這個差額是您為了達成目前環境效益所付出的「<span class="highlight-term">綠色溢價</span>」(Green Premium)。`;
                        } else {
                            insight_cost += ` 恭喜！這比使用100%資料庫原生料的基準成本降低了 <strong>${Math.abs(cost_diff_pct).toFixed(1)}%</strong>，您的永續策略同時產生了「<span class="highlight-term">綠色折扣</span>」(Green Discount)。`;
                        }
                    }
                    let strategy_cost = `材料成本是影響最終產品定價、利潤率及市場競爭力的核心財務指標。精準地量化它，是將永續設計與企業財務績效連結起來的關鍵第一步，也是採購與財務部門最關心的數據。`;
                    let advice_cost = `若要深入探討成本的構成、以及成本與碳排之間的關聯，請務必檢視下方的<b>「成本效益深度剖析」</b>模組。它將幫助您識別出「高成本、高碳排」的雙贏優化機會點。`;
                    body = build_html(def_cost, insight_cost, strategy_cost, advice_cost);
                    break;

                default:
                    body = "<p>此主題暫無詳細解讀。</p>";
                    break;
            }

            $('#interpretation-title').html(`<i class="fas fa-comment-dots me-3"></i> ${title}`);
            $('#interpretation-body').html(body);
            interpretationModal.show();
        }

        /**
         * 【V1.3 專家透明化版】渲染水資源短缺足跡 (AWARE) 計分卡的 HTML
         * @description 新增「衝擊數據透明化」模組，直接在卡片上顯示原生料基準與當前設計的衝擊值比較，讓使用者能直觀理解分數的由來。
         */
        function renderWaterScarcityScorecard(waterData) {
            if (!waterData || !waterData.success) {
                return `<div class="card h-100"><div class="card-header"><h5 class="mb-0"><i class="fas fa-hand-holding-water text-primary me-2"></i>水資源短缺足跡計分卡</h5></div><div class="card-body d-flex align-items-center justify-content-center"><p class="text-muted">數據不足，無法分析。</p></div></div>`;
            }
            const { performance_score, total_impact_m3_world_eq, virgin_impact_m3_world_eq, hotspots } = waterData;
            let scoreColorClass = 'text-success', scoreBgClass = 'bg-success-subtle border-success-subtle', scoreText = '低風險';
            if (performance_score < 40) { scoreColorClass = 'text-danger'; scoreBgClass = 'bg-danger-subtle border-danger-subtle'; scoreText = '高風險'; }
            else if (performance_score < 70) { scoreColorClass = 'text-warning'; scoreBgClass = 'bg-warning-subtle border-warning-subtle'; scoreText = '中度風險'; }

            const totalHotspotImpact = hotspots.reduce((sum, item) => sum + (item.impact || 0), 0);
            const hotspotsHtml = totalHotspotImpact > 1e-9
                ? hotspots.map(item => {
                    const contributionPct = (item.impact / totalHotspotImpact) * 100;
                    return `<div><div class='d-flex justify-content-between small'><span>${escapeHtml(item.name)}</span><span class='fw-bold'>${contributionPct.toFixed(1)}%</span></div><div class='progress' style='height: 6px;'><div class='progress-bar bg-info' style='width: ${contributionPct.toFixed(1)}%;'></div></div></div>`;
                }).join('')
                : '<div class="text-muted small">無顯著熱點。</div>';

            const hotspotName = hotspots.length > 0 ? hotspots[0].name : '';
            const insight = (performance_score < 40 && hotspotName)
                ? `<strong>策略警示：</strong>產品對水資源稀缺地區構成<strong class="text-danger">高衝擊風險</strong>。主要壓力源來自於「${escapeHtml(hotspotName)}」的用水。這在水資源日益緊張的全球背景下，可能構成嚴重的供應鏈脆弱性。`
                : (hotspotName ? `<strong>策略定位：</strong>產品的水風險在可控範圍內。主要的改善機會點在於優化「${escapeHtml(hotspotName)}」的水足跡，這將是提升整體表現分數的關鍵。` : `<strong>策略總評：</strong>產品目前的設計在水資源短缺足跡上表現優異，未發現顯著的單一衝擊熱點。`);

            return `
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas fa-hand-holding-water text-primary me-2"></i>水資源短缺足跡計分卡<span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span></h5><i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="water-scarcity-scorecard" title="這代表什麼？"></i></div>
        <div class="card-body d-flex flex-column"><div class="row g-4"><div class="col-lg-4 text-center border-end"><h6 class="text-muted">改善表現分數</h6><div class="display-3 fw-bold ${scoreColorClass}">${performance_score.toFixed(1)}</div><div class="badge fs-6 ${scoreBgClass} text-dark-emphasis border">${scoreText}</div><p class="small text-muted mt-2">(0-100, 分數越高衝擊越低)</p></div>
                <div class="col-lg-8">
                    <div class="p-3 bg-light-subtle rounded-3 mb-3">
                        <div class="row text-center">
                            <div class="col-6 border-end">
                                <small class="text-muted">原生料基準衝擊</small>
                                <p class="fw-bold fs-5 mb-0">${virgin_impact_m3_world_eq.toLocaleString(undefined, {maximumFractionDigits: 3})}<small> m³ eq.</small></p>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">當前設計衝擊</small>
                                <p class="fw-bold fs-5 mb-0 ${scoreColorClass}">${total_impact_m3_world_eq.toLocaleString(undefined, {maximumFractionDigits: 3})}<small> m³ eq.</small></p>
                            </div>
                        </div>
                    </div>
                    <h6><i class="fas fa-chart-pie text-secondary me-2"></i>主要貢獻來源 (Top 3)</h6><div class="d-flex flex-column gap-2 mt-2">${hotspotsHtml}</div>
                </div></div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3 mt-auto"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-0">${insight}</p></div></div></div>
    `;
        }

        /**
         * 【V2.0 - 風格整合版】渲染法規風險儀表板
         * @param {object} regulatoryData - 來自後端 API 的 regulatory_impact 物件
         * @returns {string} - 完整的儀表板 HTML
         */
        function renderRegulatoryRiskDashboard(regulatoryData) {
            if (!regulatoryData || !regulatoryData.success) {
                return ''; // 如果沒有數據則不顯示
            }

            const { cbam_cost_twd, cbam_items, plastic_tax_twd, virgin_plastic_weight_kg, svhc_items } = regulatoryData;

            // --- 子項目1: CBAM 區塊 ---
            let cbamHtml;
            if (cbam_cost_twd > 0) {
                cbamHtml = `
            <p class="display-6 fw-bold text-danger mb-1">${cbam_cost_twd.toLocaleString()} <small class="fs-5 text-muted">TWD</small></p>
            <p class="small text-muted mb-2">潛在碳邊境稅成本</p>
            <ul class="list-unstyled small mb-0">
                ${cbam_items.map(item => `<li><i class="fas fa-atom fa-fw text-secondary"></i> ${escapeHtml(item.name)} (${item.co2e_kg.toFixed(2)} kg CO₂e)</li>`).join('')}
            </ul>`;
            } else {
                cbamHtml = `<p class="fs-4 fw-bold text-success mb-1"><i class="fas fa-check-circle me-2"></i>低風險</p><p class="small text-muted mb-0">目前物料不屬於 CBAM 直接管制範疇。</p>`;
            }

            // --- 子項目2: 塑膠稅區塊 ---
            let plasticTaxHtml;
            if (plastic_tax_twd > 0) {
                plasticTaxHtml = `
            <p class="display-6 fw-bold text-danger mb-1">${plastic_tax_twd.toLocaleString()} <small class="fs-5 text-muted">TWD</small></p>
            <p class="small text-muted mb-0">潛在稅務成本 (基於 ${virgin_plastic_weight_kg} kg 原生塑膠)</p>`;
            } else {
                plasticTaxHtml = `<p class="fs-4 fw-bold text-success mb-1"><i class="fas fa-check-circle me-2"></i>低風險</p><p class="small text-muted mb-0">未使用需課稅的原生塑膠包材。</p>`;
            }

            // --- 子項目3: SVHC 區塊 ---
            let svhcHtml;
            if (svhc_items.length > 0) {
                svhcHtml = `
            <p class="fs-4 fw-bold text-warning mb-1"><i class="fas fa-exclamation-triangle me-2"></i>注意</p>
            <p class="small text-muted mb-2">以下組件可能含有高度關注物質 (SVHC)，需進行供應商揭露確認：</p>
            <ul class="list-unstyled small mb-0">
                ${svhc_items.map(item => `<li><i class="fas fa-flask fa-fw text-secondary"></i> ${escapeHtml(item)}</li>`).join('')}
            </ul>`;
            } else {
                svhcHtml = `<p class="fs-4 fw-bold text-success mb-1"><i class="fas fa-check-circle me-2"></i>低風險</p><p class="small text-muted mb-0">目前物料未識別出常見 SVHC。</p>`;
            }

            // --- 組合最終的 HTML ---
            return `
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-shield-alt text-primary me-2"></i>
                法規風險儀表板
                <span class="badge bg-warning-subtle text-warning-emphasis ms-2">法規 (R)</span>
            </h5>
            <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="regulatory-risk-dashboard" title="這代表什麼？" style="cursor: pointer; opacity: 0.8;"></i>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-lg-4 mb-3 mb-lg-0">
                    <div class="p-3 bg-light-subtle rounded h-100">
                        <h6 class="text-muted d-flex align-items-center border-bottom pb-2 mb-3"><i class="fas fa-landmark me-2"></i>歐盟 CBAM 風險</h6>
                        ${cbamHtml}
                    </div>
                </div>
                <div class="col-lg-4 mb-3 mb-lg-0">
                    <div class="p-3 bg-light-subtle rounded h-100">
                        <h6 class="text-muted d-flex align-items-center border-bottom pb-2 mb-3"><i class="fas fa-box me-2"></i>歐盟塑膠包裝稅風險</h6>
                        ${plasticTaxHtml}
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-3 bg-light-subtle rounded h-100">
                        <h6 class="text-muted d-flex align-items-center border-bottom pb-2 mb-3"><i class="fas fa-biohazard me-2"></i>歐盟 REACH (SVHC) 風險</h6>
                        ${svhcHtml}
                    </div>
                </div>
            </div>
        </div>
    </div>`;
        }

        /**
         * [V1.0] 產生主指標卡片的 HTML 結構
         * @returns {string} 包含所有指標卡片佔位符的 HTML 字符串
         */
        function generateKpiCardHtml(q) {
            return `
            <div class="col-12 animate__animated animate__fadeInUp">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 d-flex align-items-center">
                            <i class="fas fa-tachometer-alt me-2"></i>各項環境衝擊指標
                            <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>
                        </h5>
                        <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="impact-indicators" title="這代表什麼？" style="cursor: pointer; opacity: 0.8;"></i>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-lg-3 col-md-6 mb-4"><div id="total-co2-card-wrapper" class="d-flex align-items-center"><i class="fa-solid fa-smog fa-3x text-primary me-4"></i><div><h6 class="text-muted mb-1 d-flex align-items-center">總碳足跡 <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="main-co2" title="這代表什麼？"></i></h6><p class="h4 fw-bold mb-0" id="total-co2-card"></p><small class="text-muted" id="co2-saved-card"></small></div></div></div>
                            <div class="col-lg-3 col-md-6 mb-4"><div class="d-flex align-items-center"><i class="fa-solid fa-bolt-lightning fa-3x text-primary me-4"></i><div><h6 class="text-muted mb-1 d-flex align-items-center">總能源消耗 <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="main-energy" title="這代表什麼？"></i></h6><p class="h4 fw-bold mb-0" id="total-energy-card"></p><small class="text-muted" id="energy-saved-card"></small></div></div></div>
                            <div class="col-lg-3 col-md-6 mb-4"><div class="d-flex align-items-center"><i class="fa-solid fa-droplet fa-3x text-primary me-4"></i><div><h6 class="text-muted mb-1 d-flex align-items-center">總水資源消耗 <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="main-water" title="這代表什麼？"></i></h6><p class="h4 fw-bold mb-0" id="total-water-card"></p><small class="text-muted" id="water-saved-card"></small></div></div></div>
                            <div id="adp-kpi-container" class="col-lg-3 col-md-6 mb-4"></div>
                            <div class="col-lg-3 col-md-6 mb-4"><div class="d-flex align-items-center"><i class="fa-solid fa-cloud-rain fa-3x text-primary me-4"></i><div><h6 class="text-muted mb-1 d-flex align-items-center">酸化潛力 <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="main-acidification" title="這代表什麼？"></i></h6><p class="h4 fw-bold mb-0" id="total-acidification-card"></p><small class="text-muted" id="acidification-saved-card"></small></div></div></div>
                            <div class="col-lg-3 col-md-6 mb-4"><div class="d-flex align-items-center"><i class="fa-solid fa-seedling fa-3x text-primary me-4"></i><div><h6 class="text-muted mb-1 d-flex align-items-center">優養化潛力 <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="main-eutrophication" title="這代表什麼？"></i></h6><p class="h4 fw-bold mb-0" id="total-eutrophication-card"></p><small class="text-muted" id="eutrophication-saved-card"></small></div></div></div>
                            <div class="col-lg-3 col-md-6 mb-4"><div class="d-flex align-items-center"><i class="fa-solid fa-globe fa-3x text-primary me-4"></i><div><h6 class="text-muted mb-1 d-flex align-items-center">臭氧層破壞 <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="main-ozone" title="這代表什麼？"></i></h6><p class="h4 fw-bold mb-0" id="total-ozone-depletion-card"></p><small class="text-muted" id="ozone-saved-card"></small></div></div></div>
                            <div class="col-lg-3 col-md-6 mb-4"><div class="d-flex align-items-center"><i class="fa-solid fa-sun fa-3x text-primary me-4"></i><div><h6 class="text-muted mb-1 d-flex align-items-center">光化學煙霧 <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="main-smog" title="這代表什麼？"></i></h6><p class="h4 fw-bold mb-0" id="total-photochemical-ozone-card"></p><small class="text-muted" id="smog-saved-card"></small></div></div></div>
                        </div>
                    </div>
                </div>
            </div>`;
        }

        /**
         * 【v7.3 介面統一版】產生儀表板主圖表網格的 HTML 結構
         */
        function generateChartGridHtml() {
            const charts = [
                { id: 'lifecycleChart', title: '生命週期階段', topic: 'lifecycle' },
                { id: 'contentChart', title: '產品原料構成', topic: 'content' },
                { id: 'compositionChart', title: '產品重量組成', topic: 'composition' },
                { id: 'impactSourceChart', title: '環境熱點分析', topic: 'impact-source' },
                { id: 'impactAndSavingsChart', title: '減碳效益分析', topic: 'impact-savings' },
                { id: 'impactCompareChart', title: '標竿分析', topic: 'impact-comparison' },
            ];
            // 【核心修正】在 h6 標題中加入範疇標籤
            return charts.map(chart => `
    <div class="col-lg-4 col-md-6 mt-4 animate__animated animate__fadeInUp">
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <h6 class="card-title text-center d-flex justify-content-center align-items-center">
                    ${chart.title}
                    <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>
                    <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="${chart.topic}" title="這是什麼意思？"></i>
                </h6>
                <div class="flex-grow-1" style="height:250px;">
                    <canvas id="${chart.id}"></canvas>
                </div>
            </div>
        </div>
    </div>`).join('');
        }

        /**
         * 【V9.8 模組還原版】產生所有分析模組的 HTML 佈局骨架
         * @description 確保所有儀表板模組，包含三個「三幕劇」深度剖析，都被正確渲染。
         */
        function generateAnalysisModulesHtml(hasCostData) {
            // 商業與總體評分
            const commercialBenefitsHtml = `<div id="commercial-benefits-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const esgScorecardHtml = `<div id="esg-scorecard-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const holisticAnalysisHtml = `<div id="holistic-analysis-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const storytellingHubHtml = `<div id="storytelling-hub-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;

            // 核心圖表與儀表板
            const chartGridHtml = generateChartGridHtml(); // 6個主要圖表
            const sankeyDeepDiveHtml = `<div id="sankey-deep-dive-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const comprehensiveSgDashboardHtml = `<div id="comprehensive-sg-dashboard-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const supplyChainMapHtml = `<div id="supply-chain-map-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;

            // 細項計分卡
            const corporateReputationHtml = `<div id="corporate-reputation-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const socialScorecardHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="social-scorecard-container"></div>`;
            const governanceScorecardHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="governance-scorecard-container"></div>`;
            const envPerformanceHtml = `<div id="environmental-performance-overview-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const climateActionHtml = `<div class="col-lg-6 mt-4 animate__animated animate__fadeInUp" id="climate-action-card-container"></div>`;
            const comprehensiveCircularityHtml = `<div class="col-lg-6 mt-4 animate__animated animate__fadeInUp" id="comprehensive-circularity-card-container"></div>`;
            const waterManagementHtml = `<div id="water-management-scorecard-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const pollutionPreventionHtml = `<div class="col-lg-12 mt-4 animate__animated animate__fadeInUp" id="pollution-prevention-card-container"></div>`;
            const waterScarcityHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="water-scarcity-scorecard-container"></div>`;
            const resourceDepletionHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="resource-depletion-scorecard-container"></div>`;
            const circularityScorecardHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="circularity-scorecard-container"></div>`;
            const regulatoryRiskHtml = `<div class="col-md-12 mt-4 animate__animated animate__fadeInUp" id="regulatory-risk-container"></div>`;
            const biodiversityHtml = `<div id="biodiversity-scorecard-container" class="col-md-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const tnfdHtml = `<div id="tnfd-analysis-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const wasteScorecardHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="waste-scorecard-container"></div>`;
            const energyScorecardHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="energy-scorecard-container"></div>`;
            const acidificationScorecardHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="acidification-scorecard-container"></div>`;
            const eutrophicationScorecardHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="eutrophication-scorecard-container"></div>`;
            const ozoneDepletionScorecardHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="ozone-depletion-scorecard-container"></div>`;
            const pocpScorecardHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="pocp-scorecard-container"></div>`;
            const totalWaterHtml = `<div class="col-md-6 mt-4 animate__animated animate__fadeInUp" id="total-water-footprint-container"></div>`;
            const financialRiskSummaryHtml = `<div id="financial-risk-summary-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;



            // ▼▼▼ 【核心還原】確保三個「三幕劇」深度剖析的容器被宣告 ▼▼▼
            const sustainabilityAnalysisHtml = `<div id="sustainability-deep-dive-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            const costAnalysisHtml = hasCostData ? `<div id="cost-benefit-deep-dive-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>` : '';
            const resilienceAnalysisHtml = `<div id="resilience-deep-dive-container" class="col-12 mt-4 animate__animated animate__fadeInUp"></div>`;
            // ▲▲▲ 還原結束 ▲▲▲

            // 按照一個更具邏輯性的順序組合所有模組
            return `
        ${commercialBenefitsHtml}
        ${financialRiskSummaryHtml}
        ${costAnalysisHtml}
        ${esgScorecardHtml}
        ${holisticAnalysisHtml}
        ${storytellingHubHtml}

        ${chartGridHtml}

        ${sankeyDeepDiveHtml}
        ${comprehensiveSgDashboardHtml}
        ${supplyChainMapHtml}

        ${corporateReputationHtml}
        ${socialScorecardHtml}
        ${governanceScorecardHtml}

        ${envPerformanceHtml}
        ${climateActionHtml}
        ${comprehensiveCircularityHtml}
        ${waterManagementHtml}
        ${pollutionPreventionHtml}
        ${tnfdHtml}
        ${biodiversityHtml}
        ${waterScarcityHtml}
        ${wasteScorecardHtml}
        ${energyScorecardHtml}
        ${acidificationScorecardHtml}
        ${eutrophicationScorecardHtml}
        ${ozoneDepletionScorecardHtml}
        ${pocpScorecardHtml}
        ${totalWaterHtml}
        ${resourceDepletionHtml}
        ${circularityScorecardHtml}
        ${regulatoryRiskHtml}

        ${resilienceAnalysisHtml}
        ${sustainabilityAnalysisHtml}

        `;
        }

        /**
         * [V1.0] 產生效益換算卡片的 HTML 結構
         * @returns {string} HTML 字符串
         */
        function generateEquivalentsCardHtml() {
            const sdgHtml = generateSdgIconsHtml([6, 7, 12, 13]);
            return `
            <div id="equivalents-card-container" class="col-12 mt-4 animate__animated animate__fadeInUp">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 d-flex align-items-center">
                            <i class="fas fa-leaf text-primary me-2"></i> 總效益換算 (與100%原生料相比)
                            <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>
                        </h5>${sdgHtml}
                        <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="equivalents" title="這代表什麼？" style="cursor: pointer; opacity: 0.8;"></i>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 text-center" id="equivalents-container"></div>
                    </div>
                </div>
            </div>`;
        }

        /**
         * [V1.0] 產生結果儀表板標頭的 HTML 結構
         * @param {string} pName - 報告的標題名稱
         * @returns {string} 包含標頭和操作按鈕的 HTML 字符串
         */
        function generateResultsHeaderHtml(pName) {
            const lenses = [
                { key: 'carbon', label: '如何降低碳排？', icon: 'fa-smog' },
                { key: 'circularity', label: '如何提升循環性？', icon: 'fa-recycle' },
                { key: 'cost', label: '如何兼顧成本？', icon: 'fa-dollar-sign' },
                { key: 'risk', label: '如何管理供應鏈風險？', icon: 'fa-shield-alt' },
                { key: 'esg', label: '如何提升ESG總評？', icon: 'fa-chart-line' },
                { key: 'hotspot', label: '關鍵熱點診斷', icon: 'fa-crosshairs' },
                { key: 'marketing', label: '發掘永續亮點', icon: 'fa-star' },
                { key: 'report', label: '如何產生溝通材料？', icon: 'fa-bullhorn' }
            ];

            const lensButtonsHtml = lenses.map(lens => `
                <input type="radio" class="btn-check" name="lens-options" id="lens-${lens.key}" autocomplete="off">
                <label class="btn btn-outline-secondary btn-sm" for="lens-${lens.key}"><i class="fas ${lens.icon} me-1"></i> ${lens.label}</label>
            `).join('');

            return `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0" id="report-title">${escapeHtml(pName)} - 分析結果</h4>
                <div class="d-flex align-items-center">
<button type="button" class="btn btn-outline-secondary me-2" id="start-guided-tour-btn" title="啟動互動式功能導覽">
            <i class="fas fa-route me-2"></i><span class="d-none d-sm-inline">新手導覽</span>
        </button>
                    <button type="button" class="btn btn-outline-primary me-2" id="generate-ai-narrative-btn-header" title="讓AI為您撰寫報告摘要">
                        <i class="fas fa-comment me-2"></i><span class="d-none d-sm-inline">與AI永續分析師對話</span>
                    </button>
                    <button type="button" class="btn btn-outline-info me-2" data-bs-toggle="modal" data-bs-target="#scenarioAnalysisModal" id="open-scenario-analysis-btn">
                        <i class="fas fa-chart-line me-2"></i><span class="d-none d-sm-inline">情境分析</span>
                    </button>
                    <button type="button" class="btn btn-outline-warning me-2" data-bs-toggle="modal" data-bs-target="#aiOptimizerModal" id="open-ai-optimizer-btn">
                        <i class="fas fa-magic me-2"></i><span class="d-none d-sm-inline">AI 幫我找最佳解！</span>
                    </button>

                    <div class="btn-group me-2" role="group">
                        <button type="button" class="btn btn-outline-primary" id="save-report-btn">
                            <i class="fas fa-save me-2"></i><span class="d-none d-sm-inline">儲存</span>
                        </button>
                        <button type="button" class="btn btn-outline-success" id="view-detailed-report-btn" style="display: none;">
                            <i class="fas fa-file-invoice me-2"></i><span class="d-none d-sm-inline">檢視報告</span>
                        </button>
                    </div>

                    <div class="btn-group me-2" role="group">
                         <button type="button" class="btn btn-outline-info" id="view-carousel-slider-report-btn" style="display: none;">
                            <i class="fas fa-play-circle me-2"></i><span class="d-none d-sm-inline">輪播故事</span>
                        </button>
                    </div>

                    <div class="btn-group" id="share-export-dropdown-btn">
                        <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-share-square"></i> 分享/匯出
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" id="generate-dpp-btn"><i class="fas fa-passport fa-fw me-2"></i>產生數位產品護照 (DPP)</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#esgReportModal"><i class="fas fa-file-signature fa-fw me-2"></i>產生 ESG 框架報告摘要</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" id="share-qrcode-btn"><i class="fas fa-qrcode fa-fw me-2"></i>分享 QR Code</a></li>
                            <li><a class="dropdown-item" href="#" id="embed-btn"><i class="fas fa-code fa-fw me-2"></i>取得內嵌碼</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" id="export-pdf-btn"><i class="fas fa-file-pdf fa-fw me-2"></i>匯出儀表板 (PDF)</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="card mb-4 shadow-sm">
                <div class="card-body d-flex align-items-center gap-3">
                    <strong class="text-primary flex-shrink-0"><i class="fas fa-filter me-2"></i>分析透鏡:</strong>
                    <div class="btn-group flex-wrap" role="group">
                        <input type="radio" class="btn-check" name="lens-options" id="lens-none" autocomplete="off" checked>
                        <label class="btn btn-outline-secondary btn-sm" for="lens-none"><i class="fas fa-eye me-1"></i> 顯示全部</label>
                        ${lensButtonsHtml}
                    </div>
                </div>
            </div>
            `;
        }

        /**
         * [V1.0] 產生「資源消耗潛力(ADP)」指標卡片的 HTML 結構
         * @param {object} total_imp - 當前計算出的總衝擊數據
         * @param {object} total_v_imp - 100% 原生料的總衝擊數據
         * @returns {string} 根據數據是否存在返回對應的 HTML 字符串或空字符串
         */
        function generateAdpKpiHtml(total_imp, total_v_imp) {
            // 檢查 ADP 數據是否存在
            if (typeof total_imp.adp !== 'undefined') {
                const formatSavedText = (diff, unit) => diff >= 0
                    ? `較原生料節省 ${diff.toExponential(2)} ${unit}`
                    : `較原生料<span class="text-danger fw-bold">增加</span> ${Math.abs(diff).toExponential(2)} ${unit}`;

                const adp_diff_text = formatSavedText(total_v_imp.adp - total_imp.adp, 'kg Sb eq');

                return `<div class="d-flex align-items-center">
                    <i class="fa-solid fa-gem fa-3x text-primary me-4"></i>
                    <div>
                        <h6 class="text-muted mb-1 d-flex align-items-center">
                            資源消耗潛力
                            <i class="fas fa-comment-dots ms-2 text-primary interpretation-icon" data-topic="main-adp" title="這代表什麼？"></i>
                        </h6>
                        <p class="h4 fw-bold mb-0">${total_imp.adp.toExponential(2)} kg Sb eq</p>
                        <small class="text-muted" id="adp-saved-card">${adp_diff_text}</small>
                    </div>
                </div>`;
            } else {
                // 如果數據不存在，返回空字符串，容器會被清空
                return '';
            }
        }

        /**
         * [V1.0] 填充主指標卡片的動態數據
         * @param {object} total_imp - 當前計算出的總衝擊數據
         * @param {object} total_v_imp - 100% 原生料的總衝擊數據
         */
        function populateKpiCards(total_imp, total_v_imp) {
            const formatSavedText = (diff, unit, isExponential = true) => {
                const formatNumber = (num) => isExponential ? num.toExponential(2) : num.toLocaleString(undefined, {maximumFractionDigits:2});
                if (diff >= 0) {
                    return `較原生料節省 ${formatNumber(diff)} ${unit}`;
                } else {
                    return `較原生料<span class="text-danger fw-bold">增加</span> ${formatNumber(Math.abs(diff))} ${unit}`;
                }
            };

            $('#total-co2-card').text(`${total_imp.co2.toLocaleString(undefined, {maximumFractionDigits:3})} kg CO₂e`);
            $('#co2-saved-card').html(formatSavedText(total_v_imp.co2 - total_imp.co2, 'kg', false));

            $('#total-energy-card').text(`${total_imp.energy.toLocaleString(undefined, {maximumFractionDigits:2})} MJ`);
            $('#energy-saved-card').html(formatSavedText(total_v_imp.energy - total_imp.energy, 'MJ', false));

            $('#total-water-card').text(`${total_imp.water.toLocaleString(undefined, {maximumFractionDigits:2})} L`);
            $('#water-saved-card').html(formatSavedText(total_v_imp.water - total_imp.water, 'L', false));

            $('#total-acidification-card').text(total_imp.acidification.toExponential(2) + ' kg SO₂e');
            $('#acidification-saved-card').html(formatSavedText(total_v_imp.acidification - total_imp.acidification, 'kg SO₂e'));

            $('#total-eutrophication-card').text(total_imp.eutrophication.toExponential(2) + ' kg PO₄e');
            $('#eutrophication-saved-card').html(formatSavedText(total_v_imp.eutrophication - total_imp.eutrophication, 'kg PO₄e'));

            $('#total-ozone-depletion-card').text(total_imp.ozone_depletion.toExponential(2) + ' kg CFC-11e');
            $('#ozone-saved-card').html(formatSavedText(total_v_imp.ozone_depletion - total_imp.ozone_depletion, 'kg CFC-11e'));

            $('#total-photochemical-ozone-card').text(total_imp.photochemical_ozone.toExponential(2) + ' kg NMVOCe');
            $('#smog-saved-card').html(formatSavedText(total_v_imp.photochemical_ozone - total_imp.photochemical_ozone, 'kg NMVOCe'));

            $('#adp-kpi-container').html(generateAdpKpiHtml(total_imp, total_v_imp));
        }

        /**
         * [V1.0] 填充效益換算卡片的動態數據
         * @param {object} eq - 效益換算的數據物件
         * @param {number} q - 生產數量
         */
        function populateEquivalentsCard(eq, q) {
            const eq_container = $('#equivalents-container');
            eq_container.empty();
            const eq_labels = { car_km: ['汽車停駛(km)','fa-car-side'], tree_years: ['樹木年吸碳量','fa-tree'], flight_km: ['飛機航行(km)','fa-plane'], beef_kg: ['少吃牛肉(kg)','fa-drumstick-bite'], phone_charges: ['手機充電(次)','fa-mobile-alt'], fridge_days: ['冰箱運轉(天)','fa-snowflake'], led_bulb_hours: ['LED燈泡(小時)','fa-lightbulb'], ac_hours: ['冷氣運轉(小時)','fa-wind'], showers: ['家庭淋浴(次)','fa-shower'], toilet_flushes: ['馬桶沖水(次)','fa-toilet'], a4_sheets: ['A4紙張(張)','fa-file-alt'], washing_loads: ['洗衣機(次)','fa-jug-detergent'] };
            let positiveEquivalentsCount = 0;
            let eq_html_content = '';

            for (const [key, value] of Object.entries(eq)) {
                if (value * q > 0 && eq_labels[key]) {
                    eq_html_content += `
                        <div class="col-lg-2 col-md-3 col-4">
                           <div class="equivalent-item">
                                <div class="icon"><i class="fas ${eq_labels[key][1]}"></i></div>
                                <div class="value">${(value*q).toLocaleString(undefined,{maximumFractionDigits:1})}</div>
                                <div class="label">${eq_labels[key][0]}</div>
                            </div>
                        </div>
                    `;
                    positiveEquivalentsCount++;
                }
            }
            if (positiveEquivalentsCount === 0) {
                eq_html_content = '<div class="col-12 text-center text-muted p-3"><i class="fas fa-info-circle me-2"></i>目前的設計與100%原生料相比尚無產生正效益。</div>';
            }
            eq_container.html(eq_html_content);
        }

        /* ================================================================== */
        /* ▼▼▼【V2.0 專業升級版】供應鏈風險地圖繪製相關函式 ▼▼▼        */
        /* ================================================================== */

        // 【新增】物料大類顏色管理器
        const CATEGORY_COLORS = {};
        let lastColorIndex = 0;

        /**
         * 根據物料大類取得專屬顏色
         * @param {string} category - 物料類別名稱
         * @returns {string} - 代表顏色的十六進位碼
         */
        function getColorForCategory(category) {
            if (!CATEGORY_COLORS[category]) {
                CATEGORY_COLORS[category] = CHART_COLORS[lastColorIndex % CHART_COLORS.length];
                lastColorIndex++;
            }
            return CATEGORY_COLORS[category];
        }

        /**
         * [V1.1 - Bug 修正版] 為地圖上的單一地點建立圓餅圖 SVG 圖標
         * @param {object} countryData - 包含該國所有物料分類與權重的物件
         * @returns {string} - SVG 圖標的 HTML 字串
         */
        function createPieIconSvg(countryData) {
            const size = 40; // SVG 圖標的大小
            const radius = size / 2;
            const center = size / 2;
            let cumulativePercent = 0;
            const paths = [];

            const categories = Object.keys(countryData.materials);

            if (categories.length === 1) {
                const category = categories[0];
                const color = getColorForCategory(category);
                return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" class="pie-marker-svg">
                            <circle cx="${center}" cy="${center}" r="${radius}" fill="${color}" />
                        </svg>`;
            }

            // 當有多個分類時，才執行原本的扇形繪製邏輯
            categories.forEach(category => {
                const categoryWeight = countryData.materials[category].totalWeight;
                const percentage = categoryWeight / countryData.totalWeight * 100;
                const color = getColorForCategory(category);

                const startAngle = cumulativePercent / 100 * 360;
                const endAngle = (cumulativePercent + percentage) / 100 * 360;

                // 避免因浮點數誤差導致 endAngle 與 startAngle 完全相等
                if (Math.abs(startAngle - endAngle) < 0.001) {
                    cumulativePercent += percentage;
                    return; // 跳過幾乎為零的扇形
                }

                const startX = center + radius * Math.cos(Math.PI * (startAngle - 90) / 180);
                const startY = center + radius * Math.sin(Math.PI * (startAngle - 90) / 180);
                const endX = center + radius * Math.cos(Math.PI * (endAngle - 90) / 180);
                const endY = center + radius * Math.sin(Math.PI * (endAngle - 90) / 180);

                const largeArcFlag = percentage > 50 ? 1 : 0;

                const path = `<path d="M ${center},${center} L ${startX},${startY} A ${radius},${radius} 0 ${largeArcFlag} 1 ${endX},${endY} Z" fill="${color}"></path>`;
                paths.push(path);
                cumulativePercent += percentage;
            });

            return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" class="pie-marker-svg">${paths.join('')}</svg>`;
        }

        /**
         * 【V2.0 升級版】繪製綜合財務風險圖表 (長條圖)
         */
        function drawFinancialSummaryChart(data) {
            const canvasId = 'financialRiskSummaryChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            const theme = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];

            // 【核心修改】將圖表類型從 doughnut 改為 bar，並設定 indexAxis: 'y'
            charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.map(r => r.label),
                    datasets: [{
                        label: '曝險金額 (TWD)',
                        data: data.map(r => r.value),
                        backgroundColor: theme.chartColors.map(c => c + 'B3'), // 使用主題色
                        borderColor: theme.chartColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y', // <-- 關鍵修改：變為水平長條圖
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false } // 水平長條圖通常不需要圖例
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: '潛在財務曝險 (TWD)'
                            }
                        }
                    }
                }
            });
        }

        /**
         * 【V9.1 修正版】繪製供應鏈風險地圖 (整合總體報告與篩選功能)
         * @param {array} components - BOM 物料清單
         * @param {array} [filterKeys=[]] - (可選) 一個包含要篩選顯示的物料 key 的陣列
         */
        function drawSupplyChainMap(components, filterKeys = []) {
            const componentsToDraw = filterKeys.length > 0
                ? components.filter(c => filterKeys.includes(c.materialKey))
                : components;

            const sdgHtml = generateSdgIconsHtml([9, 12, 17]);
            const mapCardHtml = `
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-globe-americas text-primary me-2"></i>供應鏈風險地圖儀表板
                <span class="badge bg-warning-subtle text-warning-emphasis ms-2">供應鏈 (S+G)</span>
            </h5>${sdgHtml}
            <div>
                <button class="btn btn-sm btn-outline-secondary" id="reset-map-filter-btn" style="display: ${filterKeys.length > 0 ? 'inline-block' : 'none'};">
                    <i class="fas fa-undo me-1"></i>重置篩選
                </button>
                <i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="supply-chain-map" title="這代表什麼？"></i>
            </div>
        </div>
        <div class="card-body">
            <div class="row h-100"><div class="col-md-8 h-100"><div id="leaflet-map" style="height: 100%; width: 100%; border-radius: 0.375rem;"></div><div id="map-legend-container" class="position-absolute bottom-0 start-0 m-2" style="z-index: 1000;"></div></div><div class="col-md-4 border-start h-100" style="overflow-y: auto;"><div id="map-info-panel" class="p-3"></div></div></div>
        </div>
    </div>`;
            $('#supply-chain-map-container').html(mapCardHtml);

            let mapContainer = document.getElementById('leaflet-map');
            if(mapContainer && mapContainer._leaflet_id) { mapContainer._leaflet_id = null; }
            const map = L.map('leaflet-map').setView([20, 0], 2);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map);

            const totalBomWeight = components.reduce((sum, c) => sum + (parseFloat(c.weight) || 0), 0);
            const totalBomCo2 = components.reduce((sum, c) => { const material = getMaterialByKey(c.materialKey); if (!material) return sum; const recycled_ratio = (parseFloat(c.percentage) || 0) / 100; const virgin_ratio = 1 - recycled_ratio; const co2_prod = ((material.virgin_co2e_kg || 0) * virgin_ratio + (material.recycled_co2e_kg || 0) * recycled_ratio) * (parseFloat(c.weight) || 0); const eol_impact = (material.eol_recycle_credit_co2e || 0) * (parseFloat(c.weight) || 0); return sum + co2_prod + eol_impact; }, 0);
            const countryMarkers = {};

            componentsToDraw.forEach(component => {
                const material = getMaterialByKey(component.materialKey);
                if (!material || !material.country_of_origin) return;
                try {
                    const origins = JSON.parse(material.country_of_origin);
                    if (!Array.isArray(origins)) return;
                    origins.forEach(origin => {
                        const countryNameEn = origin.country;
                        const countryInfo = COUNTRY_COORDINATES[countryNameEn];
                        if (!countryInfo) return;
                        if (!countryMarkers[countryNameEn]) { countryMarkers[countryNameEn] = { coords: countryInfo.coords, countryNameEn: countryNameEn, countryNameZh: countryInfo.zh, totalWeight: 0, totalCo2: 0, weightedSocialRiskSum: 0, materials: {} }; }
                        const countryData = countryMarkers[countryNameEn];
                        const category = material.category || '未分類';
                        if (!countryData.materials[category]) { countryData.materials[category] = { totalWeight: 0, items: [] }; }
                        const materialWeightInCountry = (component.weight || 0) * (origin.percentage / 100);
                        const recycled_ratio = (parseFloat(component.percentage) || 0) / 100;
                        const virgin_ratio = 1 - recycled_ratio;
                        const co2_prod = ((material.virgin_co2e_kg || 0) * virgin_ratio + (material.recycled_co2e_kg || 0) * recycled_ratio) * materialWeightInCountry;
                        const eol_impact = (material.eol_recycle_credit_co2e || 0) * materialWeightInCountry;
                        countryData.totalCo2 += co2_prod + eol_impact;
                        countryData.totalWeight += materialWeightInCountry;
                        countryData.weightedSocialRiskSum += (material.social_risk_score || 50) * materialWeightInCountry;
                        countryData.materials[category].totalWeight += materialWeightInCountry;
                        countryData.materials[category].items.push(`<strong>${escapeHtml(material.name)}</strong> (${origin.percentage}%)`);
                    });
                } catch (e) { console.error("解析 country_of_origin 失敗:", material.country_of_origin, e); }
            });

            const generateCountryInfoHtml = (countryData) => {
                const weightConcentration = totalBomWeight > 0 ? (countryData.totalWeight / totalBomWeight * 100).toFixed(1) : 0;
                const carbonConcentration = totalBomCo2 != 0 ? (countryData.totalCo2 / totalBomCo2 * 100).toFixed(1) : 0;
                const avgRiskScore = countryData.totalWeight > 0 ? countryData.weightedSocialRiskSum / countryData.totalWeight : 0;
                const riskColor = avgRiskScore >= 70 ? '#dc3545' : avgRiskScore >= 40 ? '#ffc107' : '#198754';

                let infoHtml = `<h5 class="border-bottom pb-2 mb-3">${escapeHtml(countryData.countryNameZh)}</h5>
            <div class="row gx-2 mb-3"><div class="col-6 text-center"><div class="small text-muted">供應鏈集中度</div><div class="fw-bold fs-5">${weightConcentration}%</div></div><div class="col-6 text-center"><div class="small text-muted">碳排集中度</div><div class="fw-bold fs-5">${carbonConcentration}%</div></div></div>
            <div class="small d-flex justify-content-between align-items-center p-2 bg-light-subtle rounded-3 mb-3"><span>加權平均社會風險</span><span class="badge fs-6" style="background-color:${riskColor}; color:white;">${avgRiskScore.toFixed(1)}</span></div>
            <h6>此地區來源物料：</h6>`;

                Object.keys(countryData.materials).forEach(category => {
                    const categoryColor = getColorForCategory(category);
                    infoHtml += `<p class="small mb-0 mt-2"><strong style="color:${categoryColor};"><i class="fas fa-square me-1"></i> ${escapeHtml(category)}</strong></p><ul class="small ps-3 mb-1">${countryData.materials[category].items.join('')}</ul>`;
                });
                return infoHtml;
            };

            const generateGlobalSummaryHtml = (allCountryData, bomWeight, bomCo2) => {
                const countries = Object.values(allCountryData);
                if (countries.length === 0) return `<div class="text-center text-muted mt-5"><i class="fas fa-map-marked-alt fa-2x mb-3"></i><p>目前 BOM 表中沒有<br>可供分析的供應來源國</p></div>`;
                const top3Weight = [...countries].sort((a,b) => b.totalWeight - a.totalWeight).slice(0, 3);
                const top3Co2 = [...countries].sort((a,b) => b.totalCo2 - a.totalCo2).slice(0, 3);
                const top3Risk = [...countries].sort((a,b) => (b.weightedSocialRiskSum / (b.totalWeight||1)) - (a.weightedSocialRiskSum / (a.totalWeight||1))).slice(0, 3);
                const topListHtml = (list, bomTotal, dataKey) => list.map(item => { const value = (dataKey === 'risk') ? ((item.weightedSocialRiskSum / (item.totalWeight||1))).toFixed(1) : (item[dataKey] / bomTotal * 100).toFixed(1) + '%'; return `<li class="list-group-item d-flex justify-content-between align-items-center p-1 bg-transparent border-0"><small>${escapeHtml(item.countryNameZh)}</small><span class="badge bg-secondary rounded-pill">${value}</span></li>`; }).join('');
                return `<h5 class="border-bottom pb-2 mb-3">供應鏈總體報告</h5><div class="mb-3"><div class="d-flex justify-content-between align-items-center"><h6 class="small text-muted mb-1">來源國總數</h6><div class="fw-bold fs-5">${countries.length}</div></div></div><div class="mb-3"><h6 class="small text-muted border-bottom pb-1 mb-2">Top 3 供應鏈集中度 (依重量)</h6><ul class="list-group list-group-flush">${topListHtml(top3Weight, bomWeight, 'totalWeight')}</ul></div><div class="mb-3"><h6 class="small text-muted border-bottom pb-1 mb-2">Top 3 碳排集中度</h6><ul class="list-group list-group-flush">${topListHtml(top3Co2, bomCo2, 'totalCo2')}</ul></div><div><h6 class="small text-muted border-bottom pb-1 mb-2">Top 3 社會風險來源國</h6><ul class="list-group list-group-flush">${topListHtml(top3Risk, 1, 'risk')}</ul></div>`;
            };

            const updateMapInfoPanel = (countryData) => {
                const panel = $('#map-info-panel');
                if (!countryData) {
                    panel.html(generateGlobalSummaryHtml(countryMarkers, totalBomWeight, totalBomCo2));
                } else {
                    panel.html(generateCountryInfoHtml(countryData));
                }
            };

            Object.values(countryMarkers).forEach(countryData => {
                if(countryData.totalWeight <= 0) return;
                const categoryClasses = Object.keys(countryData.materials).map(cat => `cat-${cat.replace(/\s+/g, '-')}`).join(' ');
                const pieIcon = L.divIcon({ html: createPieIconSvg(countryData), className: `pie-marker-icon ${categoryClasses}`, iconSize: [40, 40], iconAnchor: [20, 20] });
                const marker = L.marker(countryData.coords, { icon: pieIcon }).addTo(map);

                marker.on('mouseover', () => updateMapInfoPanel(countryData));
                marker.on('mouseout', () => updateMapInfoPanel(null));
                const popupContent = generateCountryInfoHtml(countryData);
                marker.bindPopup(popupContent, { minWidth: 280 });
            });

            let legendHtml = '<div class="leaflet-legend"><h6>物料類別</h6>';
            for (const category in CATEGORY_COLORS) {
                const categoryClass = `cat-${category.replace(/\s+/g, '-')}`;
                legendHtml += `<div class="legend-item" data-category-class="${categoryClass}"><div class="legend-color-box" style="background-color: ${CATEGORY_COLORS[category]}"></div>${escapeHtml(category)}</div>`;
            }
            legendHtml += '</div>';
            $('#map-legend-container').html(legendHtml);

            updateMapInfoPanel(null);

            setTimeout(() => { map.invalidateSize() }, 200);
        }

        /**
         * 【V2.0 全新】繪製 TNFD 風險與機會熱點圖表
         * @param {object} tnfdData - 來自後端的 TNFD 分析結果
         */
        function drawTnfdCharts(tnfdData) {
            const riskCtx = document.getElementById('tnfdRiskChart')?.getContext('2d');
            const oppCtx = document.getElementById('tnfdOpportunityChart')?.getContext('2d');

            if (!riskCtx || !oppCtx) return;

            if (charts['tnfdRiskChart']) charts['tnfdRiskChart'].destroy();
            if (charts['tnfdOpportunityChart']) charts['tnfdOpportunityChart'].destroy();

            const baseOptions = {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => ` 評分: ${ctx.raw}` } }
                },
                scales: {
                    x: { beginAtZero: true, max: 5, ticks: { stepSize: 1 } },
                    y: { ticks: { font: { size: 10 } } }
                }
            };

            if (tnfdData.top_risks && tnfdData.top_risks.length > 0) {
                charts['tnfdRiskChart'] = new Chart(riskCtx, {
                    type: 'bar',
                    data: {
                        labels: tnfdData.top_risks.map(r => r.text),
                        datasets: [{
                            label: '風險分數',
                            data: tnfdData.top_risks.map(r => r.score),
                            backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        }]
                    },
                    options: { ...baseOptions }
                });
            } else {
                $(riskCtx.canvas).parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted">無顯著風險</div>');
            }

            if (tnfdData.top_opportunities && tnfdData.top_opportunities.length > 0) {
                charts['tnfdOpportunityChart'] = new Chart(oppCtx, {
                    type: 'bar',
                    data: {
                        labels: tnfdData.top_opportunities.map(o => o.text),
                        datasets: [{
                            label: '機會分數',
                            data: tnfdData.top_opportunities.map(o => o.score),
                            backgroundColor: 'rgba(25, 135, 84, 0.7)',
                        }]
                    },
                    options: { ...baseOptions }
                });
            } else {
                $(oppCtx.canvas).parent().html('<div class="d-flex align-items-center justify-content-center h-100 text-muted">無顯著機會</div>');
            }
        }

        // ▼▼▼ 【V4.1 - AI 對話模式完整版】AI 功能的全部 JavaScript 邏輯 ▼▼▼

        // 用於儲存對話歷史
        let chatHistory = [];
        // 用於儲存冷卻計時器
        let cooldownInterval;
        const COOLDOWN_SECONDS = 15; // 設定冷卻時間為 15 秒

        // 輔助函式：將訊息加入到聊天記錄中
        function appendToChatLog(role, content) {
            const chatLog = $('#ai-chat-log');
            let messageHtml = '';

            // 將 Markdown 轉為 HTML
            const formattedContent = (typeof marked !== 'undefined') ? marked.parse(content) : content.replace(/\n/g, '<br>');

            if (role === 'user') {
                messageHtml = `
                    <div class="d-flex justify-content-end mb-3">
                        <div class="p-3 rounded-3" style="background-color: var(--bs-primary-bg-subtle); max-width: 80%;">
                            <div class="small">${formattedContent}</div>
                        </div>
                    </div>`;
            } else { // 'model' (AI) 或 'system' (系統訊息)
                const bgColor = (role === 'system') ? 'var(--bs-danger-bg-subtle)' : 'var(--bs-light-bg-subtle)';
                messageHtml = `
                    <div class="d-flex justify-content-start mb-3">
                        <div class="p-3 rounded-3 small" style="background-color: ${bgColor}; max-width: 80%;">${formattedContent}</div>
                    </div>`;
            }

            // 如果是第一則訊息，則清空預設提示
            if (chatLog.find('.text-muted.p-5').length > 0) {
                chatLog.empty();
            }
            chatLog.append(messageHtml);
            // 捲動到底部
            chatLog.scrollTop(chatLog[0].scrollHeight);
        }

        // 1. 主控台的「生成AI摘要」按鈕，現在只負責「打開並重置」彈出視窗
        $(document).on('click', '#generate-ai-narrative-btn-header', function() {
            if (!perUnitData) {
                alert('請先進行一次分析。');
                return;
            }

            // 重置彈出視窗到初始狀態
            const chatLog = $('#ai-chat-log');
            const generateBtn = $('#generate-narrative-in-modal-btn');
            const copyBtn = $('#copy-ai-narrative-btn');
            const chatInputContainer = $('#ai-chat-input-container');

            // 清除可能存在的計時器
            clearInterval(cooldownInterval);
            chatHistory = []; // 清空歷史紀錄

            // 恢復UI
            chatLog.html('<div class="text-center text-muted p-5"><i class="fas fa-lightbulb fa-2x mb-3"></i><p>請先選擇分析視角，然後點擊上方按鈕產生初始報告。</p></div>');
            copyBtn.hide();
            chatInputContainer.hide();
            generateBtn.prop('disabled', false).html('<i class="fas fa-magic me-2"></i>產生初始報告');

            const aiModal = new bootstrap.Modal('#aiNarrativeModal');
            aiModal.show();
        });

        // 2. 彈出視窗內部的「產生初始報告」按鈕
        $(document).on('click', '#generate-narrative-in-modal-btn', function() {
            const btn = $(this);
            const chatLog = $('#ai-chat-log');

            chatHistory = []; // 開始新對話時清空歷史

            // 顯示讀取中訊息 (此處不變)
            chatLog.html('<div class="d-flex align-items-center text-muted p-4"><div class="spinner-border spinner-border-sm me-3" role="status"></div><span>AI 永續分析師正在產生初始報告...</span></div>');
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>AI 運算中...');

            const payload = { reportData: perUnitData, persona: $('#ai-persona-selector').val() };

            $.ajax({
                url: '?action=generate_narrative',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json',
                success: function(response) {
                    // 【核心修正】在顯示新內容前，先清空對話框
                    chatLog.empty();

                    if (response.success) {
                        chatHistory.push({ role: 'model', content: response.narrative });
                        appendToChatLog('model', response.narrative);
                        $('#ai-chat-input-container').slideDown();

                        // 動態加入「複製」按鈕
                        $('#copy-ai-narrative-btn').remove(); // 先移除舊的，避免重複
                        $('#aiNarrativeModal .modal-footer').prepend('<button type="button" class="btn btn-outline-secondary me-auto" id="copy-ai-narrative-btn"><i class="fas fa-copy me-2"></i>複製報告內容</button>');

                    } else {
                        appendToChatLog('system', `<strong class="text-danger">生成失敗：</strong> ${escapeHtml(response.message)}`);
                    }
                },
                error: function(jqXHR) {
                    // 【核心修正】在顯示錯誤訊息前，也先清空對話框
                    chatLog.empty();
                    appendToChatLog('system', `<strong class="text-danger">通訊錯誤，請稍後再試。</strong>`);
                },
                complete: function() {
                    // 冷卻計時器邏輯 (不變)
                    let secondsLeft = COOLDOWN_SECONDS;
                    btn.prop('disabled', true).text(`請等待 ${secondsLeft} 秒後再試`);

                    cooldownInterval = setInterval(function() {
                        secondsLeft--;
                        btn.text(`請等待 ${secondsLeft} 秒後再試`);
                        if (secondsLeft <= 0) {
                            clearInterval(cooldownInterval);
                            btn.prop('disabled', false).html('<i class="fas fa-redo me-2"></i>重新生成 (不同人格)');
                        }
                    }, 1000);
                }
            });
        });

        // 3. 處理對話框的「發送」按鈕點擊事件
        $(document).on('click', '#ai-chat-send-btn', function() {
            const input = $('#ai-chat-input');
            const question = input.val().trim();
            if (!question) return;

            chatHistory.push({ role: 'user', content: question });
            appendToChatLog('user', question);
            input.val('').focus();

            appendToChatLog('model', '<div class="spinner-border spinner-border-sm" role="status"></div>');

            const payload = {
                reportData: perUnitData,
                persona: $('#ai-persona-selector').val(),
                chatHistory: chatHistory,
                newQuestion: question
            };

            $.ajax({
                url: '?action=chat_follow_up',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json',
                success: function(response) {
                    $('#ai-chat-log .spinner-border').closest('.d-flex').remove();
                    if (response.success) {
                        chatHistory.push({ role: 'model', content: response.narrative });
                        appendToChatLog('model', response.narrative);
                    } else {
                        appendToChatLog('system', `<strong class="text-danger">回應失敗：</strong> ${response.message}`);
                    }
                },
                error: function() {
                    $('#ai-chat-log .spinner-border').closest('.d-flex').remove();
                    appendToChatLog('system', `<strong class="text-danger">通訊錯誤，請稍後再試。</strong>`);
                }
            });
        });

        // 4. 讓使用者可以在輸入框按 Enter 發送
        $(document).on('keypress', '#ai-chat-input', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#ai-chat-send-btn').click();
            }
        });

        // 5. 「複製報告內容」按鈕的邏輯
        $(document).on('click', '#copy-ai-narrative-btn', function() {
            // 將聊天記錄轉換為純文字
            let textToCopy = "AI 永續分析師報告\n========================\n\n";
            chatHistory.forEach(entry => {
                const prefix = (entry.role === 'user') ? '您提問：' : 'AI 回應：';
                textToCopy += `${prefix}\n${entry.content}\n\n`;
            });

            navigator.clipboard.writeText(textToCopy.trim()).then(() => {
                const btn = $(this);
                const originalHtml = btn.html();
                btn.html('<i class="fas fa-check me-2"></i>已複製!');
                setTimeout(() => btn.html(originalHtml), 2000);
            }).catch(err => {
                console.error('複製失敗: ', err);
                alert('複製內容失敗，請手動選取複製。');
            });
        });


        /**
         * 【V9.8 模組還原版】初始化所有儀表板模組
         * @description 確保所有模組的繪製函式都被正確呼叫，包含三個「三幕劇」深度剖析。
         */
        function initializeDashboardModules(perUnitData) {
            // 重置物料大類的顏色映射，確保每次分析顏色一致
            Object.keys(CATEGORY_COLORS).forEach(key => delete CATEGORY_COLORS[key]);
            lastColorIndex = 0;

            const { impact: imp, virgin_impact: v_imp, charts, inputs, environmental_fingerprint_scores } = perUnitData;

            // --- 1. 繪製所有基礎圖表與儀表板 ---
            drawChart('lifecycleChart', getLifecycleChartConfig(charts.lifecycle_co2));
            drawChart('contentChart', getContentChartConfig(charts.content_by_type));
            drawChart('compositionChart', getCompositionChartConfig(charts.composition));
            drawChart('impactSourceChart', getImpactSourceChartConfig(charts.impact_by_material));
            drawChart('impactAndSavingsChart', getImpactAndSavingsChartConfig(charts.savings_by_material));
            drawChart('impactCompareChart', getImpactCompareChartConfig({co2: imp.co2, energy: imp.energy, water: imp.water}, {co2: v_imp.co2, energy: v_imp.energy, water: v_imp.water}));

            const analysis = perUnitData.holistic_analysis;
            $('#holistic-analysis-container').html(renderHolisticAnalysisCard(analysis, perUnitData));
            drawRadarChart(analysis.radar_data);

            // --- 2. 渲染所有由後端生成 HTML 的儀表板 ---
            if (perUnitData.esg_scorecard_html) { $('#esg-scorecard-container').html(perUnitData.esg_scorecard_html); }
            if (perUnitData.corporate_reputation_html) { $('#corporate-reputation-container').html(perUnitData.corporate_reputation_html); }
            if (perUnitData.social_scorecard_html) { $('#social-scorecard-container').html(perUnitData.social_scorecard_html); }
            if (perUnitData.governance_scorecard_html) { $('#governance-scorecard-container').html(perUnitData.governance_scorecard_html); }
            if (perUnitData.tnfd_analysis_html) { $('#tnfd-analysis-container').html(perUnitData.tnfd_analysis_html); if (perUnitData.tnfd_analysis?.success) { drawTnfdCharts(perUnitData.tnfd_analysis); } }
            if (perUnitData.waste_scorecard_html) $('#waste-scorecard-container').html(perUnitData.waste_scorecard_html);
            if (perUnitData.energy_scorecard_html) $('#energy-scorecard-container').html(perUnitData.energy_scorecard_html);
            if (perUnitData.acidification_scorecard_html) $('#acidification-scorecard-container').html(perUnitData.acidification_scorecard_html);
            if (perUnitData.eutrophication_scorecard_html) $('#eutrophication-scorecard-container').html(perUnitData.eutrophication_scorecard_html);
            if (perUnitData.ozone_depletion_scorecard_html) $('#ozone-depletion-scorecard-container').html(perUnitData.ozone_depletion_scorecard_html);
            if (perUnitData.photochemical_ozone_scorecard_html) $('#pocp-scorecard-container').html(perUnitData.photochemical_ozone_scorecard_html);
            if (perUnitData.circularity_scorecard_html) { $('#circularity-scorecard-container').html(perUnitData.circularity_scorecard_html); }
            if (perUnitData.biodiversity_scorecard_html) { $('#biodiversity-scorecard-container').html(perUnitData.biodiversity_scorecard_html); }
            if (perUnitData.resource_depletion_scorecard_html) { $('#resource-depletion-scorecard-container').html(perUnitData.resource_depletion_scorecard_html); }
            if (perUnitData.regulatory_impact) { $('#regulatory-risk-container').html(renderRegulatoryRiskDashboard(perUnitData.regulatory_impact)); }
            if (perUnitData.environmental_performance_overview_html) { $('#environmental-performance-overview-container').html(perUnitData.environmental_performance_overview_html); }
            if (perUnitData.climate_action_scorecard_html) { $('#climate-action-card-container').html(perUnitData.climate_action_scorecard_html); drawLifecycleBreakdownChart(perUnitData.charts.lifecycle_co2); drawCarbonHotspotChart(perUnitData.charts.composition); }
            if (perUnitData.comprehensive_circularity_scorecard_html) { $('#comprehensive-circularity-card-container').html(perUnitData.comprehensive_circularity_scorecard_html); drawCircularityBreakdownChart(perUnitData, perUnitData.environmental_performance); }
            if (perUnitData.pollution_prevention_scorecard_html) { $('#pollution-prevention-card-container').html(perUnitData.pollution_prevention_scorecard_html); drawPollutionBreakdownChart(perUnitData.environmental_performance); }
            if (perUnitData.water_management_scorecard_html) { $('#water-management-scorecard-container').html(perUnitData.water_management_scorecard_html); drawWaterBreakdownChart(perUnitData.environmental_performance); }
            if (perUnitData.water_scarcity_scorecard_html) { $('#water-scarcity-scorecard-container').html(perUnitData.water_scarcity_scorecard_html); }

            if (perUnitData.total_water_footprint_card_html) {
                $('#total-water-footprint-container').html(perUnitData.total_water_footprint_card_html);
            }
            if (perUnitData.social_scorecard_html) {
                $('#social-scorecard-container').html(perUnitData.social_scorecard_html);
            }
            if (perUnitData.governance_scorecard_html) {
                $('#governance-scorecard-container').html(perUnitData.governance_scorecard_html);
            }
            if (perUnitData.regulatory_risk_dashboard_html) {
                $('#regulatory-risk-container').html(perUnitData.regulatory_risk_dashboard_html);

                const financialRiskDataEl = document.getElementById('financialRiskChart');
                if (financialRiskDataEl) {
                    try {
                        const riskData = JSON.parse(financialRiskDataEl.dataset.risks);
                        drawFinancialRiskChart(riskData);
                    } catch(e) {
                        console.error("無法解析財務風險圖表數據:", e);
                    }
                }
            }
            if (perUnitData.financial_risk_summary_html) {
                $('#financial-risk-summary-container').html(perUnitData.financial_risk_summary_html);
                const chartEl = document.getElementById('financialRiskSummaryChart');
                if (chartEl && chartEl.dataset.risks) {
                    try {
                        const chartData = JSON.parse(chartEl.dataset.risks);
                        drawFinancialSummaryChart(chartData);
                    } catch(e) { console.error("無法解析財務風險摘要圖表數據:", e); }
                }
            }

            // --- 3. 渲染整合式儀表板 (S&G, Sankey) ---
            if (perUnitData.comprehensive_sg_dashboard_html) {
                $('#comprehensive-sg-dashboard-container').html(perUnitData.comprehensive_sg_dashboard_html);
                if (perUnitData.social_impact && perUnitData.governance_impact && perUnitData.sg_hotspots) {
                    renderComprehensiveSgDashboard(perUnitData.social_impact, perUnitData.governance_impact, perUnitData.sg_hotspots);
                }
            }
            if (perUnitData.sankey_deep_dive_html) {
                $('#sankey-deep-dive-container').html(perUnitData.sankey_deep_dive_html);
                if (perUnitData.impact.cost > 0) {
                    $('#cost-flow-tab').parent().show();
                } else {
                    $('#cost-flow-tab').parent().hide();
                    if ($('#cost-flow-tab').hasClass('active')) {
                        new bootstrap.Tab($('#mass-flow-tab')).show();
                    }
                }
                const activeMode = $('#sankeyTab .nav-link.active').attr('id').split('-')[0];
                renderSankeyChart(perUnitData, activeMode);
            }

            // --- 4. 渲染溝通策略中心與地圖 ---
            if (perUnitData.story_score) {
                renderStorytellingHub(perUnitData);
            }
            drawSupplyChainMap(perUnitData.inputs.components, []);

            // --- 5. 【核心還原】呼叫「三幕劇」深度剖析儀表板的渲染函式 ---
            const hasCostData = perUnitData.impact.cost > 0;

            // 5.1 永續性深度剖析 (Sustainability Deep Dive)
            $('#sustainability-deep-dive-container').html(generateSustainabilityDeepDiveHtml());
            const sustainabilityProfile = generateSustainabilityNarratives_Expert(perUnitData);
            const susTitle = `深度剖析：${sustainabilityProfile.name} <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>`;
            $('#sustainability-deep-dive-container .card-header h5').html(`<i class="fas fa-search-location text-primary me-2"></i>${susTitle}`);
            drawCategoryCharts(prepareCategoryData(charts.composition));
            drawImpactMatrixChart(prepareMatrixData(charts.composition, inputs.totalWeight));
            drawWaterfallChart(prepareWaterfallData(charts.composition, imp.co2));
            showAct(1);

            // 5.2 成本效益深度剖析 (Cost-Benefit Deep Dive)
            if (hasCostData) {
                $('#cost-benefit-deep-dive-container').html(generateCostBenefitDeepDiveHtml());
                generateCostBenefitNarratives_Expert(perUnitData);
                drawCostCompositionChart(prepareCostCompositionData(charts.composition));
                drawCostCarbonChart(prepareCostCarbonData(charts.composition));
                drawCostComparisonChart(prepareCostComparisonData(charts.composition));
                showCostAct(1);
            }

            // 5.3 環境指紋深度剖析 (Environmental Resilience Deep Dive)
            $('#resilience-deep-dive-container').html(generateResilienceDeepDiveHtml());
            const resilienceProfile = generateResilienceNarratives(perUnitData);
            const resilienceTitle = `環境指紋：${resilienceProfile.name} <span class="badge bg-success-subtle text-success-emphasis ms-2">環境 (E)</span>`;
            $('#resilience-deep-dive-container .card-header h5').html(`<i class="fas fa-fingerprint text-primary me-2"></i>${resilienceTitle}`);
            if (environmental_fingerprint_scores) {
                drawEnvironmentalFingerprintChart('resilienceFingerprintChart', environmental_fingerprint_scores);
            }
            if (charts.multi_criteria_hotspots) {
                drawResilienceStackedHotspotChart(prepareResilienceHotspotData(charts.multi_criteria_hotspots), charts.composition, imp);
                const minScore = Object.entries(environmental_fingerprint_scores).reduce((min, score) => score[1] < min[1] ? score : min, Object.entries(environmental_fingerprint_scores)[0]);
                drawTradeoffMatrixChart(charts.multi_criteria_hotspots, minScore[0]);
            }
            showResilienceAct(1);

            // --- 6. 更新 UI 狀態與初始化 ---
            if (perUnitData.view_id) {
                $('#save-report-btn').hide();
                $('#view-detailed-report-btn, #view-carousel-slider-report-btn').show();
            } else {
                $('#save-report-btn').show();
                $('#view-detailed-report-btn, #view-carousel-slider-report-btn').hide();
            }
            const avgRecycledPct = perUnitData.inputs.totalWeight > 0 ? (perUnitData.charts.content_by_type.recycled / perUnitData.inputs.totalWeight * 100) : 0;
            $('#sankey-simulator-slider').val(avgRecycledPct.toFixed(0));
            $('#sankey-simulator-value').text(`${avgRecycledPct.toFixed(0)}%`);
            $('[data-bs-toggle="tooltip"]').tooltip('dispose').tooltip(); // 重新初始化 Tooltips
        }

        /**
         * [V5.3 簡化版] 儀表板總控制器
         * @description 移除財務儀表板的顯示/隱藏邏輯，交由 recalculateAndDisplayCommercialBenefits 統一處理。
         */
        function updateDashboard() {
            if (!perUnitData) return;

            const q = parseInt($('#productionQuantity').val()) || 1;
            const pName = perUnitData.versionName || '分析結果';
            const total_imp = {}, total_v_imp = {};
            for(const key in perUnitData.impact){ total_imp[key] = perUnitData.impact[key] * q; }
            for(const key in perUnitData.virgin_impact){ total_v_imp[key] = perUnitData.virgin_impact[key] * q; }

            const resultsHeaderHtml = generateResultsHeaderHtml(pName);
            const hasCostData = perUnitData.impact.cost > 0;
            const resultsBodyHtml =
                generateKpiCardHtml(q) +
                generateAnalysisModulesHtml(hasCostData) +
                generateEquivalentsCardHtml();

            $('#results-panel-wrapper').html(resultsHeaderHtml);
            $('#results-panel-wrapper').append(`<div id="results-panel" class="row g-4">${resultsBodyHtml}</div>`);

            populateKpiCards(total_imp, total_v_imp);
            populateEquivalentsCard(perUnitData.equivalents, q);
            initializeDashboardModules(perUnitData);

            // 【核心修改】只需在最後呼叫一次即時計算函式，它會自動處理所有財務儀表板的顯示/隱藏
            recalculateAndDisplayCommercialBenefits();
        }

        // ===================================================================
// ▼▼▼【分析透鏡功能 修正版】▼▼▼
// ===================================================================

        /**
         * 【V2.1 - 修正版】全局高亮引擎
         * @description 接收透鏡名稱，高亮儀表板上所有相關卡片，並增加元素存在性檢查。
         * @param {string} lens - 要啟動的透鏡名稱
         */
        function applyStaticHighlighting(lens) {
            const wrapper = $('#results-panel-wrapper');
            // 移除舊的 lens 狀態
            wrapper.removeClass('lens-active');
            $('.lens-highlight').removeClass('lens-highlight');
            $('.lens-annotation').remove();
            $('.lens-overlay').remove(); // 移除遮罩層

            if (lens === 'none' || !perUnitData) return;

            // 為所有卡片加上半透明遮罩
            wrapper.find('.row > [class*="col-"] > .card, .row > [class*="col-"] > div:not([id="holistic-analysis-container"]) > .card').each(function() {
                $(this).append('<div class="lens-overlay"></div>');
            });
            wrapper.addClass('lens-active');

            // 【核心修正】更新並擴充 lensConfig，納入所有新儀表板
            const lensConfig = {
                carbon: [
                    { selector: '#total-co2-card-wrapper', annotation: '關鍵指標' },
                    { selector: '#climate-action-card-container', annotation: '氣候行動儀表板' },
                    { selector: '#sustainability-deep-dive-container', annotation: '深度剖析' },
                    { selector: '#open-ai-optimizer-btn', annotation: 'AI 尋求最佳解' }
                ],
                circularity: [
                    { selector: '#comprehensive-circularity-card-container', annotation: '綜合儀表板' },
                    { selector: '#sankey-deep-dive-container', annotation: '物質流分析' },
                    { selector: '#contentChart', annotation: '原料構成' },
                    { selector: '#eol-card', isControlPanel: true, annotation: '生命終端設定' }
                ],
                cost: [
                    { selector: '#commercial-benefits-container', annotation: '商業決策儀表板' },
                    { selector: '#cost-benefit-deep-dive-container', annotation: '成本效益剖析' },
                    { selector: '#open-scenario-analysis-btn', annotation: '財務風險模擬' }
                ],
                risk: [
                    { selector: '#corporate-reputation-container', annotation: '供應鏈聲譽 (S+G)' },
                    { selector: '#supply-chain-map-container', annotation: '地理風險地圖' },
                    { selector: '#regulatory-risk-container', annotation: '法規風險' },
                    { selector: '#tnfd-analysis-container', annotation: '自然風險 (TNFD)' },
                    { selector: '#resilience-deep-dive-container', annotation: '衝擊權衡分析' }
                ],
                esg: [
                    { selector: '#esg-scorecard-container', annotation: 'ESG 總評級' },
                    { selector: '#social-scorecard-container', annotation: '社會(S)細項' },
                    { selector: '#governance-scorecard-container', annotation: '治理(G)細項' },
                    { selector: '#environmental-performance-overview-container', annotation: '環境(E)細項' }
                ],
                hotspot: [
                    { selector: '#holistic-analysis-container', annotation: 'AI 識別熱點' },
                    { selector: '#impactSourceChart', annotation: '熱點貢獻圖' },
                    { selector: '#sustainability-deep-dive-container', annotation: '熱點成因剖析' }
                ],
                marketing: [
                    { selector: '#storytelling-hub-container', annotation: '溝通策略中心' },
                    { selector: '#equivalents-card-container', annotation: '效益故事化' },
                    { selector: '#esg-scorecard-container', annotation: '權威評級' },
                    { selector: '#commercial-benefits-container', annotation: '商業價值' }
                ],
                report: [
                    { selector: '#save-report-btn', annotation: '儲存報告' },
                    { selector: '#share-export-dropdown-btn', annotation: '分享與匯出' },
                    { selector: '#open-history-btn', annotation: '版本比較' },
                    { selector: '#generate-ai-narrative-btn-header', annotation: 'AI 撰寫摘要' }
                ]
            };

            const highlights = lensConfig[lens];
            if (highlights) {
                highlights.forEach(item => {
                    let elementToHighlight = item.isControlPanel ? $(item.selector) : wrapper.find(item.selector);

                    // 【核心修正】增加元素存在性檢查
                    if (elementToHighlight.length > 0) {
                        if (!elementToHighlight.is('button') && !elementToHighlight.hasClass('btn-group')) {
                            elementToHighlight = elementToHighlight.closest('.card, [id$="-container"]');
                        }
                        if (elementToHighlight.length > 0) {
                            elementToHighlight.addClass('lens-highlight');
                            if(item.annotation) {
                                elementToHighlight.append(`<div class="lens-annotation">${item.annotation}</div>`);
                            }
                        }
                    }
                });
            }
        }

        /**
         * 【V2.1 - 修正版】新手導覽引擎 (原 startLensTour)
         * @description 使用 Shepherd.js 建立一個引導式的、步驟化的分析流程。
         * @param {string} lens - 要啟動的導覽主題 (e.g., 'carbon', 'circularity')
         */
        function startGuidedTour(lens) {
            if (lens === 'none' || !perUnitData) return;

            // 實例化一個新的導覽
            const tour = new Shepherd.Tour({
                useModalOverlay: true,
                defaultStepOptions: {
                    classes: 'shadow-md',
                    scrollTo: { behavior: 'smooth', block: 'center' },
                    cancelIcon: { enabled: true },
                    buttons: [
                        { action() { return this.back(); }, secondary: true, text: '上一步' },
                        { action() { return this.next(); }, text: '下一步' }
                    ]
                }
            });

            // 【補完】為所有透鏡主題新增引導步驟
            const tourConfig = {
                carbon: [
                    { selector: '#total-co2-card-wrapper', title: '第一步：檢視關鍵指標', text: '首先，確認產品的「總碳足跡」績效。下方的「較原生料節省」是衡量您減碳成效最直接的證據。' },
                    { selector: '#climate-action-card-container', title: '第二步：診斷碳排熱點', text: '利用「氣候行動計分卡」，從生命週期與物料組成兩個維度，找出產品最主要的碳排貢獻來源。' },
                    { selector: '#open-ai-optimizer-btn', title: '第三步：尋求 AI 最佳解', text: '在鎖定熱點後，讓 AI 引擎在您的約束條件下（如：成本不增加），為您自動尋找能最大化降低碳排的替代材料或設計方案。' }
                ],
                circularity: [
                    { selector: '#comprehensive-circularity-card-container', title: '第一步：檢視循環指數', text: '確認產品的「綜合循環經濟分數」。分數越高，代表產品的循環經濟程度越高。' },
                    { selector: '#sankey-deep-dive-container', title: '第二步：分析物質流', text: '切換到「物質流」頁籤，可視覺化地看到再生料與原生料的流動關係，以及最終廢棄物的去向。' },
                    { selector: '#eol-card', title: '第三步：調整回收情境', text: '在左側操作面板，調整產品在生命週期結束時的「回收率」，這將直接影響MCI分數與總碳足跡。', isControlPanel: true }
                ],
                cost: [
                    { selector: '#commercial-benefits-container', annotation: '商業決策儀表板', title: '第一步：連結永續與商業', text: '首先，從「商業決策儀表板」了解您的永續設計選擇，是帶來了「綠色溢價」還是「綠色折扣」，並評估對毛利率的影響。' },
                    { selector: '#cost-benefit-deep-dive-container', annotation: '成本效益剖析', title: '第二步：深度剖析成本結構', text: '利用「三幕劇」的分析流程，找出成本與碳排的「雙重熱點」，這是能同時創造財務與環境效益的 Win-Win 機會點。' },
                    { selector: '#open-scenario-analysis-btn', annotation: '財務風險模擬', title: '第三步：進行財務壓力測試', text: '最後，透過「情境分析」模擬未來關稅、匯率等風險，量化其對產品總成本的具體財務衝擊。' }
                ],
                risk: [
                    { selector: '#corporate-reputation-container', annotation: '供應鏈聲譽 (S+G)', title: '第一步：評估 S+G 風險', text: '從「永續供應鏈聲譽儀表板」快速了解產品在社會(S)與治理(G)兩個面向的綜合風險評分。' },
                    { selector: '#supply-chain-map-container', annotation: '地理風險地圖', title: '第二步：視覺化地理風險', text: '透過互動式地圖，直觀地識別您的供應鏈是否過度集中在某些高風險國家或地區。' },
                    { selector: '#regulatory-risk-container', annotation: '法規風險', title: '第三步：量化法規衝擊', text: '此儀表板能模擬歐盟 CBAM、塑膠稅等法規，將未來的法規風險，轉化為可預估的財務成本。' },
                    { selector: '#tnfd-analysis-container', annotation: '自然風險 (TNFD)', title: '第四步：評估自然相關風險', text: '回應最新的 TNFD 框架，評估您的供應鏈對生物多樣性與自然資本的依賴程度與潛在風險。' }
                ],
                esg: [
                    { selector: '#esg-scorecard-container', annotation: 'ESG 總評級', title: '第一步：檢視總體評級', text: '從「產品ESG永續績效」儀表板了解產品在 E、S、G 三大構面的綜合表現與最終評級。' },
                    { selector: '#environmental-performance-overview-container', annotation: '環境(E)細項', title: '第二步：下鑽環境(E)構面', text: '此儀表板將 E 分數拆解為氣候、循環、水資源等五大構面，幫助您識別環境表現的優勢與短版。' },
                    { selector: '#corporate-reputation-container', annotation: '供應鏈聲譽 (S+G)', title: '第三步：檢視 S+G 綜合風險', text: '從這裡了解供應鏈在社會與治理兩個面向的綜合風險，這是 ESG 評分的重要組成部分。' }
                ],
                hotspot: [
                    { selector: '#holistic-analysis-container', annotation: 'AI 識別熱點', title: '第一步：AI 智慧診斷', text: '在「綜合分析與建議」中，AI 會自動為您識別出產品最主要的「環境熱點」，也就是貢獻最多碳排的單一物料。' },
                    { selector: '#climate-action-card-container', annotation: '熱點貢獻圖', title: '第二步：視覺化衝擊', text: '在「氣候行動計分卡」的右側圖表中，您可以清楚看到這個熱點物料與其他組件的碳排貢獻比較。' },
                    { selector: '#open-ai-optimizer-btn', annotation: 'AI 尋求最佳解', title: '第三步：AI 尋找解決方案', text: '在鎖定熱點後，點擊此按鈕，讓 AI 為您自動尋找能解決此熱點問題的最佳替代方案。' }
                ],
                marketing: [
                    { selector: '#storytelling-hub-container', annotation: '溝通策略中心', title: '第一步：定義溝通策略', text: '從「永續溝通策略中心」開始，AI 會為您的產品診斷出一個核心的「故事原型」，並提供針對不同目標受眾的溝通指南。' },
                    { selector: '#equivalents-card-container', annotation: '效益故事化', title: '第二步：將數據故事化', text: '利用此模組，將抽象的減碳、節水成效，轉化為消費者能輕易理解的生活化比喻，例如「相當於少開多少公里汽車」。' },
                    { selector: '#generate-comms-content-btn', annotation: 'AI 產生文案', title: '第三步：一鍵產生文案', text: '最後，點擊此按鈕，AI 將根據前面定義的策略與數據，為您自動產生新聞稿、社群貼文等專業溝通材料。' }
                ],
                report: [
                    { selector: '#save-report-btn', annotation: '儲存報告', title: '第一步：儲存分析版本', text: '完成分析後，第一步是點擊「儲存」按鈕，將這次的分析結果保存到您的專案歷程中。' },
                    { selector: '#share-export-dropdown-btn', annotation: '分享與匯出', title: '第二步：分享與匯出成果', text: '您可以透過多種方式分享您的報告，例如產生可掃描的 QR Code、可嵌入網站的 iframe，或直接匯出為 PDF 檔案。' },
                    { selector: '#open-history-btn', annotation: '版本比較', title: '第三步：進行版本比較', text: '您可以從「分析歷程」中，選取任兩個版本，系統將自動產生詳細的差異化比較儀表板，量化您的設計改善成效。' }
                ]
            };


            const stepsData = tourConfig[lens];
            if (!stepsData) {
                console.warn('此主題尚無引導教學:', lens);
                return;
            }

            stepsData.forEach((stepInfo, index) => {
                let attachToElement = stepInfo.isControlPanel ? $(stepInfo.selector)[0] : $('#results-panel-wrapper').find(stepInfo.selector)[0];

                // 如果元素是按鈕組的一部分，則高亮整個按鈕組
                const btnGroup = $(attachToElement).closest('.btn-group')[0];
                if(btnGroup) {
                    attachToElement = btnGroup;
                }

                if (!attachToElement) {
                    console.warn(`導覽步驟 #${index} 的目標元素 "${stepInfo.selector}" 不存在。`);
                    return; // 如果找不到元素，就跳過這一步
                }
                tour.addStep({
                    id: `step-${index}`,
                    title: stepInfo.title,
                    text: stepInfo.text,
                    attachTo: { element: attachToElement, on: 'bottom' }
                });
            });

            if (tour.steps.length > 0) {
                tour.start();
            }
        }

        // --- 【修正版】事件監聽器 ---

        // 1. "分析透鏡" 按鈕現在直接觸發「全局高亮」
        $(document).on('change', 'input[name="lens-options"]', function() {
            if (this.checked) {
                const selectedLens = this.id.replace('lens-', '');
                applyStaticHighlighting(selectedLens);
            }
        });

        // 2. 新的 "新手導覽" 按鈕負責啟動「引導式教學」
        $(document).on('click', '#start-guided-tour-btn', function() {
            if (!perUnitData) {
                Swal.fire({
                    icon: 'info',
                    title: '請先開始分析',
                    text: '您需要先在左側面板加入物料並點擊「分析效益」，才能啟動新手導覽功能。'
                });
                return;
            }

            // 使用 SweetAlert2 彈出一個選擇視窗
            Swal.fire({
                title: '選擇您想了解的主題',
                input: 'select',
                inputOptions: {
                    'carbon': '如何降低碳排？',
                    'circularity': '如何提升循環性？',
                    'cost': '如何兼顧成本？',
                    'risk': '如何管理供應鏈風險？',
                    'esg': '如何提升ESG總評？',
                    'hotspot': '如何診斷關鍵熱點？',
                    'marketing': '如何發掘永續亮點？',
                    'report': '如何產生溝通材料？'
                },
                inputPlaceholder: '請選擇一個主題',
                showCancelButton: true,
                confirmButtonText: '開始導覽 <i class="fas fa-route ms-2"></i>',
                cancelButtonText: '取消'
            }).then((result) => {
                // 如果使用者做出了選擇，則啟動對應主題的導覽
                if (result.isConfirmed && result.value) {
                    startGuidedTour(result.value);
                }
            });
        });

// ===================================================================
// ▲▲▲【分析透鏡功能 修正版 結束】▲▲▲
// ===================================================================

        /**
         * [專家版] 產生「成本效益深度剖析」模組的三幕劇策略解讀
         * @param {object} data - 來自後端的完整計算結果
         */
        function generateCostBenefitNarratives_Expert(data) {
            const compositionData = data.charts.composition;
            const totalCost = data.impact.cost;
            const totalCo2 = data.impact.co2;
            if (!compositionData || totalCost <= 0) return;

            // --- 第一幕：策略畫像 - 成本與碳排的連結 ---
            const topSpender = [...compositionData].sort((a, b) => (b.cost || 0) - (a.cost || 0))[0];
            const topEmitter = [...compositionData].sort((a, b) => (b.co2 || 0) - (a.co2 || 0))[0];
            const topSpenderPct = (topSpender.cost / totalCost) * 100;

            const isAligned = topSpender.name === topEmitter.name;
            const COST_CONCENTRATION_THRESHOLD = 50;

            let profile = {};
            if (topSpenderPct >= COST_CONCENTRATION_THRESHOLD) {
                if (isAligned) {
                    profile = { name: '雙重熱點型 (Win-Win)', color: 'success', icon: 'fa-coins' };
                    insight1 = `產品的財務與環境成本高度集中於同一個來源。<b>「${escapeHtml(topSpender.name)}」</b>不僅是成本最高的組件（佔比 <b>${topSpenderPct.toFixed(0)}%</b>），同時也是最大的碳排來源。`;
                    strategy1 = `這是一個理想的「Win-Win」局面。永續團隊與採購/財務團隊的目標完全一致，任何對此「雙重熱點」的成功優化，都將直接轉化為財務與環境的雙重收益。`;
                    advice1 = `您的行動方案非常明確：所有資源應優先集中處理<b>「${escapeHtml(topSpender.name)}」</b>。建議立即為其啟動價值工程與低碳替代方案的聯合評估。`;
                } else {
                    profile = { name: '財務壓力型', color: 'danger', icon: 'fa-dollar-sign' };
                    insight1 = `產品的成本壓力主要來自於相對環保的組件。成本最高的<b>「${escapeHtml(topSpender.name)}」</b>（佔比 <b>${topSpenderPct.toFixed(0)}%</b>）並不是主要的碳排來源。`;
                    strategy1 = `此畫像揭示了永續策略與成本控制之間的潛在衝突。您的「綠色設計」可能伴隨著高昂的財務成本，而單純的降本策略可能會損害產品的環境表現。`;
                    advice1 = `建議的策略是「分而治之」。一方面，對<b>「${escapeHtml(topSpender.name)}」</b>進行價值工程以降本。另一方面，獨立地對碳排熱點<b>「${escapeHtml(topEmitter.name)}」</b>尋求低成本的環保改進方案。`;
                }
            } else {
                if (isAligned) {
                    profile = { name: '隱藏的協同效益', color: 'primary', icon: 'fa-search-dollar' };
                    insight1 = `雖然產品的成本與碳排分佈都較為分散，但數據顯示，成本最高與碳排最高的組件恰好是同一個：<b>「${escapeHtml(topSpender.name)}」</b>。`;
                    strategy1 = `這揭示了一個「隱藏的協同效益」機會。儘管它不是一個佔據絕對主導地位的熱點，但針對它進行優化，依然能以最高的效率同時達成降本與減碳的雙重目標。`;
                    advice1 = `在缺乏明顯熱點的情況下，將<b>「${escapeHtml(topSpender.name)}」</b>作為您第一階段的優化目標，是風險最低、效率最高的策略選擇。`;
                } else {
                    profile = { name: '脫鉤的決策', color: 'secondary', icon: 'fa-random' };
                    insight1 = `產品的成本與碳排來源完全「脫鉤」。成本最高的組件<b>「${escapeHtml(topSpender.name)}」</b>與碳排最高的組件<b>「${escapeHtml(topEmitter.name)}」</b>是不同的個體。`;
                    strategy1 = `此畫像意味著您的「成本優化策略」與「氣候變遷應對策略」是兩條獨立的平行線，需要分別進行管理。這對跨部門溝通與資源協調提出了更高的要求。`;
                    advice1 = `建議成立一個跨職能團隊（包含採購、研發、永續），分別為成本熱點與碳排熱點制定優化方案，並確保兩個方案之間不會產生新的衝突。`;
                }
            }
            const title1_cost = `第一幕：成本掃描 - <span class="badge fs-6 text-${profile.color} bg-${profile.color}-subtle border border-${profile.color}-subtle"><i class="fas ${profile.icon} me-2"></i>${profile.name}</span>`;
            $('#narrative-cost-macro').html(buildNarrativeBlock(title1_cost, insight1, strategy1, advice1));

            // --- 第二、三幕 (保持原有邏輯) ---
            generateCostPositioningNarrative(compositionData); // Act 2
            generateCostComparisonNarrative(compositionData, totalCost, data.virgin_impact.cost); // Act 3
        }

        /**
         * 準備「堆疊式熱點分析圖」所需的數據結構。
         * @param {object} hotspotData - 來自後端的 multi_criteria_hotspots 數據
         * @returns {object} - 符合 Chart.js 堆疊長條圖格式的物件
         */
        function prepareResilienceHotspotData(hotspotData) {
            const impactLabels = {
                co2: '氣候變遷', acidification: '酸化', eutrophication: '優養化',
                ozone_depletion: '臭氧層破壞', photochemical_ozone: '光化學煙霧'
            };
            const labels = Object.keys(hotspotData).map(key => impactLabels[key] || key);

            // 找出所有獨一無二的物料名稱
            const materialNames = [...new Set(Object.values(hotspotData).flatMap(impact => impact.components.map(c => c.name)))];

            const datasets = materialNames.map(name => {
                const data = labels.map(labelKey => {
                    // 找到對應的 impact key (e.g., 'co2', 'acidification')
                    const originalKey = Object.keys(impactLabels).find(key => impactLabels[key] === labelKey) || labelKey;
                    const impactData = hotspotData[originalKey];
                    if (!impactData) return 0;

                    const component = impactData.components.find(c => c.name === name);
                    // 我們需要的是貢獻的百分比
                    return component ? Math.max(0, component.percent) : 0;
                });
                return { label: name, data: data };
            });

            return { labels, datasets };
        }

        /**
         * 【V7.2 全新整合版 - 單一組件邏輯強化版】繪製「堆疊式熱點分析圖」或「材料特性剖面圖」。
         * @param {object} chartData - 由 prepareResilienceHotspotData 產生的數據
         * @param {array} compositionData - 完整的 BOM 組成數據，用於判斷組件數量
         * @param {object} impactData - 產品的總體衝擊數據
         */
        function drawResilienceStackedHotspotChart(chartData, compositionData, impactData) {
            const canvasId = 'resilienceStackedHotspotChart';
            const ctx = document.getElementById(canvasId)?.getContext('2d');
            if (!ctx) return;
            if (charts[canvasId]) charts[canvasId].destroy();

            const themeConfig = THEMES[$('html').attr('data-theme')] || THEMES['light-green'];

            // 【核心升級】判斷是否為單一組件情境
            if (compositionData && compositionData.length === 1) {
                // --- 單一組件情境：繪製「材料特性剖面圖」 ---
                const impactValues = {
                    '氣候變遷': impactData.co2,
                    '酸化潛力': impactData.acidification,
                    '優養化潛力': impactData.eutrophication,
                    '臭氧層破壞': impactData.ozone_depletion,
                    '光化學煙霧': impactData.photochemical_ozone,
                    '能源消耗': impactData.energy,
                    '水資源消耗': impactData.water
                };

                const absoluteValues = Object.values(impactValues).map(v => Math.abs(v));
                const maxValue = Math.max(...absoluteValues);

                // 正規化處理，將最大衝擊的項目設為 100
                const normalizedData = Object.values(impactValues).map(v => (maxValue > 0) ? (Math.abs(v) / maxValue) * 100 : 0);

                const config = {
                    type: 'bar',
                    data: {
                        labels: Object.keys(impactValues),
                        datasets: [{
                            label: '相對衝擊強度',
                            data: normalizedData,
                            backgroundColor: themeConfig.chartColors[1] + 'B3',
                            borderColor: themeConfig.chartColors[1],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const originalLabel = context.label;
                                        const originalValue = impactValues[originalLabel];
                                        return ` 原始衝擊值: ${originalValue.toExponential(2)}`;
                                    }
                                }
                            },
                            title: {
                                display: true,
                                text: '單一材料：環境特性剖面分析 (正規化)'
                            }
                        },
                        scales: {
                            x: {
                                title: { display: true, text: '相對衝擊強度 (最大衝擊=100)' },
                                min: 0,
                                max: 100
                            }
                        }
                    }
                };
                charts[canvasId] = new Chart(ctx, config);

            } else {
                // --- 多組件情境：維持原有的「堆疊式熱點分析圖」 ---
                const config = {
                    type: 'bar',
                    data: chartData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) { label += ': '; }
                                        if (context.parsed.y !== null) { label += context.parsed.y.toFixed(1) + '%'; }
                                        return label;
                                    }
                                }
                            },
                            title: {
                                display: true,
                                text: '多組件：衝擊貢獻來源分析'
                            }
                        },
                        scales: {
                            x: { stacked: true },
                            y: {
                                stacked: true,
                                title: { display: true, text: '衝擊貢獻 (%)' },
                                max: 100,
                                min: 0
                            }
                        }
                    }
                };
                // 為每個物料分配一個顏色
                chartData.datasets.forEach((dataset, index) => {
                    dataset.backgroundColor = themeConfig.chartColors[index % themeConfig.chartColors.length] + 'B3';
                });
                charts[canvasId] = new Chart(ctx, config);
            }
        }

        /**
         * 【V3.1 供應鏈路徑版】觸發一次完整的後端計算分析
         * @description 收集BOM、使用階段、運輸階段等所有使用者輸入，打包成 payload 發送到後端。
         */
        function triggerCalculation() {
            // 為了防止重複觸發，只在按鈕可用時執行
            if (!$('#calculate-btn').is(':disabled')) {
                $('#calculatorForm').trigger('submit');
            }
        }

        /**
         * 【V12.3 - 專業除錯版】主計算觸發函式
         * @description 升級了錯誤處理機制，能解析後端回傳的詳細除錯資訊並顯示。
         */
        $('#calculatorForm').on('submit', function(e) {
            e.preventDefault();
            const $btn = $('#calculate-btn');
            $btn.html('<span class="spinner-border spinner-border-sm"></span> 分析中...').prop('disabled', true);

            // --- (打包 payload 的邏輯保持不變) ---
            const components = [];
            const materialKeyToIndexMap = {};
            $('#materials-list-container').children('.material-row').each(function() {
                const row = $(this);
                const key = row.data('key');
                if (key && row.find('.material-weight').val() && parseFloat(row.find('.material-weight').val()) > 0) {
                    const componentIndex = components.length;
                    materialKeyToIndexMap[key] = componentIndex;
                    components.push({
                        componentType: 'material',
                        materialKey: key,
                        weight: row.find('.material-weight').val(),
                        percentage: row.find('.material-percentage').val(),
                        cost: row.find('.material-cost').val(),
                        transportRoute: row.data('transport-route') || null,
                        transportOverrides: row.data('transport-overrides') ? JSON.parse(row.data('transport-overrides')) : null
                    });
                }
            });
            $('#materials-list-container').children('.process-row').each(function() {
                const row = $(this);
                const processKey = row.data('key');
                const appliedToSelector = row.find('.applied-to-selector')[0];
                const appliedToKeys = appliedToSelector.tomselect ? appliedToSelector.tomselect.getValue() : [];
                const appliedToIndices = appliedToKeys.map(key => materialKeyToIndexMap[key]).filter(index => index !== undefined);
                if (processKey && appliedToIndices.length > 0) {
                    const selectedOptions = {};
                    row.find('.process-option-select').each(function() {
                        selectedOptions[$(this).data('option-key')] = $(this).val();
                    });
                    components.push({
                        componentType: 'process',
                        processKey: processKey,
                        quantity: row.find('.process-quantity').val(),
                        selectedOptions: selectedOptions,
                        appliedToComponentIndices: appliedToIndices
                    });
                }
            });

            if (components.filter(c => c.componentType === 'material').length === 0) {
                alert('請至少新增一項有效的物料組件。');
                $btn.html('<i class="fas fa-chart-pie"></i> 分析效益').prop('disabled', false);
                return;
            }
            const payload = {
                components: components,
                use_phase: {
                    // 【⬇️ 核心修正 3/3】在這裡加入 enabled 狀態
                    enabled: $('#enableUsePhase').is(':checked'),
                    lifespan: $('#usePhaseLifespan').val(),
                    kwh: $('#usePhaseKwh').val(),
                    region: $('#usePhaseGrid').val(),
                    water: $('#usePhaseWater').val(),
                    scenarioKey: $('input[name="usePhaseMode"]:checked').val() === 'simple' ? $('#usePhaseScenarioSelector').val() : null
                },
                transport_phase: {
                    enabled: $('#enableTransportPhase').is(':checked'),
                    global_route: $('#globalTransportRoute').val()
                },
                versionName: $('#versionName').val(),
                inputs: {
                    organizationId: $('#project-organization-selector').val(),
                    projectId: $('#projectSelector').val(),
                    newProjectName: $('#newProjectName').val(),
                    productionQuantity: $('#productionQuantity').val() || 1,
                    totalWeight: parseFloat($('#total-weight-display').text()) || 0,
                    sellingPrice: $('#sellingPriceInput').val() || 0,
                    manufacturingCost: $('#manufacturingCostInput').val() || 0,
                    sgaCost: $('#sgaCostInput').val() || 0,
                },
                eol: {
                    recycle: parseFloat($('#eolRecycle').val()) || 0,
                    incinerate: parseFloat($('#eolIncinerate').val()) || 0,
                    landfill: parseFloat($('#eolLandfill').val()) || 0
                }
            };

            // --- 發送 AJAX 請求 ---
            $.ajax({
                url: '',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        perUnitData = data;
                        perUnitData.versionName = payload.versionName;
                        perUnitData.inputs.projectId = payload.inputs.projectId;
                        perUnitData.inputs.newProjectName = payload.inputs.newProjectName;
                        updateDashboard();
                        saveState();
                        $('#initial-message').hide();
                        $('#results-panel-wrapper').fadeIn(400);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: '分析失敗',
                            text: data.error || '發生未知錯誤。'
                        });
                    }
                },
                // ▼▼▼ 【核心除錯功能】升級版的錯誤處理器 ▼▼▼
                error: function(jqXHR, textStatus, errorThrown) {
                    let errorTitle = '分析時發生錯誤';
                    let errorHtml = `伺服器通訊錯誤: ${jqXHR.status}. 請檢查PHP錯誤日誌。`;

                    // 檢查後端是否回傳了我們自訂的詳細除錯 JSON
                    if (jqXHR.responseJSON && jqXHR.responseJSON.debug_info) {
                        const debug = jqXHR.responseJSON.debug_info;
                        errorTitle = '後端執行錯誤！';
                        // 將除錯資訊格式化為更易讀的 HTML
                        errorHtml = `
                    <div class="text-start small" style="text-align: left;">
                        <p><strong><i class="fas fa-exclamation-triangle text-danger"></i> 錯誤訊息：</strong> ${escapeHtml(debug.message)}</p>
                        <p><strong><i class="fas fa-file-code text-info"></i> 錯誤檔案：</strong> ${escapeHtml(debug.file)}</p>
                        <p><strong><i class="fas fa-list-ol text-warning"></i> 錯誤行號：</strong> ${escapeHtml(debug.line)}</p>
                        <hr>
                        <p><strong>追蹤路徑 (Trace)：</strong></p>
                        <pre style="white-space: pre-wrap; word-break: break-all; max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 5px;">${escapeHtml(debug.trace)}</pre>
                    </div>`;
                    } else if (jqXHR.responseText) {
                        // 如果不是 JSON，可能是 PHP 原始的錯誤訊息，直接顯示
                        errorHtml = `<pre style="white-space: pre-wrap; word-break: break-all; max-height: 300px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 5px;">${escapeHtml(jqXHR.responseText)}</pre>`;
                    }

                    // 使用 SweetAlert2 來顯示這個詳細的錯誤報告
                    Swal.fire({
                        icon: 'error',
                        title: errorTitle,
                        html: errorHtml,
                        width: '800px'
                    });

                    // 更新主畫面的錯誤提示
                    displayError('伺服器發生錯誤，請查看彈出視窗的詳細資訊。');
                },
                // ▲▲▲ 除錯功能結束 ▲▲▲
                complete: () => $btn.html('<i class="fas fa-redo"></i> 重新分析').prop('disabled', false)
            });
        });

        // 製程
        // 【核心修改】移除/修改組件時的邏輯
        $('#materials-list-container').on('click', '.remove-component-btn', function() {
            if ($('#materials-list-container').children().length > 1) {
                $(this).closest('.material-row, .process-row').remove();
                // 重新編號
                $('#materials-list-container').children().each(function(newIndex) {
                    $(this).attr('data-index', newIndex);
                    const typeText = $(this).data('component-type') === 'process' ? '製程' : '組件';
                    $(this).find('span.fw-bold:first').text(`${typeText} #${newIndex + 1}`);
                });
                updateTotalWeight();
                updateAllAppliedToSelectors(); // 【重要】移除物料後也要更新選單
                saveState();
                if (perUnitData) triggerCalculation();
            } else { /* ... */ }
        });

        /**
         * 【全新功能 - 介面升級版】填充製程選擇視窗
         * - 新增顯示數據來源
         * - 搜尋邏輯已包含分類
         */
        function populateProcessBrowser(searchTerm = '') {
            searchTerm = searchTerm.trim().toLowerCase();
            const listContainer = $('#process-browser-list');
            listContainer.empty();

            const filtered = ALL_PROCESSES.filter(p =>
                p.name.toLowerCase().includes(searchTerm) ||
                (p.category && p.category.toLowerCase().includes(searchTerm))
            );

            if (filtered.length === 0) {
                listContainer.html('<p class="text-center text-muted p-3">找不到符合條件的製程。</p>');
                return;
            }

            const grouped = filtered.reduce((acc, p) => {
                const category = p.category || '未分類';
                if (!acc[category]) acc[category] = [];
                acc[category].push(p);
                return acc;
            }, {});

            let html = '';
            for (const category in grouped) {
                html += `<h6 class="text-muted ps-3 pt-3 mb-1">${escapeHtml(category)}</h6>`;
                html += '<div class="list-group list-group-flush">';
                grouped[category].forEach(p => {
                    // 【核心修正】在 a 標籤中加入 data_source 資訊
                    const source = p.data_source ? `(${escapeHtml(p.data_source)})` : '';
                    html += `<a href="#" class="list-group-item list-group-item-action" data-key="${escapeHtml(p.process_key)}">${escapeHtml(p.name)} <small class="text-muted">${source}</small></a>`;
                });
                html += '</div>';
            }
            listContainer.html(html);
        }

        // 替換您現有的 'add-process-btn' 點擊事件
        $('#add-process-btn').on('click', function() {
            // 【核心修正】在打開 modal 前，清除 targetIndex，明確表示這是「新增」操作
            $('#processBrowserModal').removeData('targetIndex');
            populateProcessBrowser();
            processBrowserModal.show();
        });

        $('#process-browser-search').on('input', function() {
            populateProcessBrowser($(this).val());
        });

        // 【最關鍵的修正】替換您現有的 'process-browser-list' 點擊事件
        // 這個新版本會檢查 targetIndex 來決定要新增還是更新
        $('#process-browser-list').on('click', '.list-group-item', function(e) {
            e.preventDefault();
            const newKey = $(this).data('key');
            // 取得儲存在 modal 上的 targetIndex
            const targetIndex = $('#processBrowserModal').data('targetIndex');

            if (targetIndex !== undefined && targetIndex !== null) {
                // 如果 targetIndex 存在，代表是「更換」操作
                updateProcessRow(targetIndex, newKey);
            } else {
                // 如果 targetIndex 不存在，代表是「新增」操作
                addProcessRow({ processKey: newKey });
            }

            processBrowserModal.hide();
            saveState();
        });

        // 替換您現有的 'change-process-btn' 點擊事件 (這段通常已正確，此處為確保)
        $('#materials-list-container').on('click', '.change-process-btn', function() {
            const index = $(this).closest('.process-row').data('index');
            // 【核心】設定 targetIndex，明確表示這是「更換」操作
            $('#processBrowserModal').data('targetIndex', index);
            populateProcessBrowser();
            processBrowserModal.show();
        });

        // 製程

        $(document).on('click', '#save-report-btn', function() {
            if (!perUnitData) {
                alert('沒有可儲存的分析結果。');
                return;
            }
            const $btn = $(this);
            $btn.html('<span class="spinner-border spinner-border-sm me-2"></span>儲存中...').prop('disabled', true);
            $.ajax({
                url: '?action=save_report',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(perUnitData),
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $btn.html('<i class="fas fa-check me-2"></i>已儲存').removeClass('btn-outline-primary').addClass('btn-success').fadeOut(400, function() {
                            $('#view-detailed-report-btn, #view-carousel-slider-report-btn').fadeIn(400);
                        });

                        if (response.view_id) {
                            perUnitData.view_id = response.view_id;
                        }

                        // ▼▼▼ 【核心修改】檢查後端是否回傳了新專案資訊 ▼▼▼
                        if (response.newProject) {
                            const newProj = response.newProject;
                            // 1. 動態建立新的 <option>
                            const newOption = $('<option>', {
                                value: newProj.id,
                                text: escapeHtml(newProj.name)
                            });
                            // 2. 將新選項加入下拉選單
                            $('#projectSelector').append(newOption);
                            // 3. 自動選中這個新專案
                            $('#projectSelector').val(newProj.id);
                            // 4. 觸發 change 事件，這會自動隱藏「新專案名稱」輸入框
                            $('#projectSelector').trigger('change');
                            // 5. 清空已使用的「新專案名稱」輸入框
                            $('#newProjectName').val('');
                            // 6. 更新本地狀態
                            saveState();
                        }
                        // ▲▲▲ 修改結束 ▲▲▲

                    } else {
                        alert('儲存失敗：' + (response.message || '未知錯誤'));
                        $btn.html('<i class="fas fa-save me-2"></i>儲存').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('儲存報告時發生伺服器通訊錯誤。');
                    $btn.html('<i class="fas fa-save me-2"></i>儲存').prop('disabled', false);
                }
            });
        });

        $('#theme-selector').on('change', function() { applyTheme($(this).val()); });
        $('#versionName, #productionQuantity').on('input', function() { saveState(); if (perUnitData) updateDashboard(); });
        $('#materials-list-container')
            // 當重量、再生比例、成本變動時
            .on('change', '.material-weight, .material-percentage, .material-cost', () => {
                updateTotalWeight();
                saveState();
                if (perUnitData) triggerCalculation();
            })
            // 當點擊「更換物料」按鈕時
            .on('click', '.change-material-btn', function() {
                const index = $(this).closest('.material-row').data('index');
                $('#materialBrowserModal').data('targetIndex', index);
                populateMaterialBrowser();
                materialBrowserModal.show();
            })
            // 當點擊「移除物料」按鈕時
            .on('click', '.remove-material-btn', function() {
                if ($('.material-row').length > 1) {
                    $(this).closest('.material-row').remove();
                    // 重新編號以確保UI和後續邏輯正確
                    $('.material-row').each(function(newIndex) {
                        $(this).data('index', newIndex).attr('data-index', newIndex);
                        $(this).find('span.fw-bold:first').text(`組件 #${newIndex + 1}`);
                    });
                    updateTotalWeight();
                    saveState();
                    if (perUnitData) triggerCalculation();
                } else {
                    alert('至少需保留一項物料。您可直接修改此項，或使用「一鍵清除」按鈕。');
                }
            })
            // 當點擊「填入參考成本」按鈕時
            .on('click', '.suggest-cost-btn', function() {
                const row = $(this).closest('.material-row');
                const material = getMaterialByKey(row.data('key'));
                if (material?.cost_per_kg) {
                    row.find('.material-cost').val(material.cost_per_kg).trigger('change');
                } else {
                    alert('此物料無參考成本。');
                }
            });

        // EOL 輸入框邏輯
        $('#eolRecycle').on('input', function() { let recycleVal = parseFloat($(this).val()) || 0, landfillVal = parseFloat($('#eolLandfill').val()) || 0; recycleVal = Math.max(0, Math.min(100, recycleVal)); $(this).val(recycleVal); let incinerateVal = 100.0 - recycleVal - landfillVal; if (incinerateVal < 0) { landfillVal = 100.0 - recycleVal; incinerateVal = 0; $('#eolLandfill').val(landfillVal); } $('#eolIncinerate').val(incinerateVal); validateEol(); saveState(); if (perUnitData) triggerCalculation(); });
        $('#eolIncinerate').on('input', function() { let recycleVal = parseFloat($('#eolRecycle').val()) || 0, incinerateVal = parseFloat($(this).val()) || 0; incinerateVal = Math.max(0, Math.min(100.0 - recycleVal, incinerateVal)); $(this).val(incinerateVal); const landfillVal = 100.0 - recycleVal - incinerateVal; $('#eolLandfill').val(landfillVal); validateEol(); saveState(); if (perUnitData) triggerCalculation(); });
        $('#eolLandfill').on('input', function() { let recycleVal = parseFloat($('#eolRecycle').val()) || 0, landfillVal = parseFloat($(this).val()) || 0; landfillVal = Math.max(0, Math.min(100.0 - recycleVal, landfillVal)); $(this).val(landfillVal); const incinerateVal = 100.0 - recycleVal - landfillVal; $('#eolIncinerate').val(incinerateVal); validateEol(); saveState(); if (perUnitData) triggerCalculation(); });
        $(document).on('blur', '.eol-input', function() { $(this).val((parseFloat($(this).val()) || 0).toFixed(2)); });

        // 物料清單與瀏覽器按鈕
        $(document).on('click', '#add-material-btn', function() { $('#materialBrowserModal').data('targetIndex', 'new'); populateMaterialBrowser(); materialBrowserModal.show(); });
        $(document).on('click', '#clear-list-btn', () => { if (confirm('您確定要清除所有物料嗎？')) { $('#materials-list-container').empty(); addMaterialRow(); updateTotalWeight(); saveState(); perUnitData = null; $('#results-panel-wrapper').hide(); $('#initial-message').show(); $('#calculate-btn').html('<i class="fas fa-chart-pie"></i> 分析效益'); } });

        $('#material-browser-list').on('click', '.list-group-item', function(e) {
            e.preventDefault();
            const newKey = $(this).data('key');
            const targetIndex = $('#materialBrowserModal').data('targetIndex');

            if (targetIndex === 'new') {
                // 檢查畫面上是否只有一個空的物料列
                const rows = $('.material-row');
                if (rows.length === 1 && !rows.first().data('key')) {
                    // 如果是，則更新這個空白列，而不是新增
                    updateMaterialRow(0, newKey);
                } else {
                    // 否則，正常新增一列
                    addMaterialRow({ materialKey: newKey });
                }
            } else {
                updateMaterialRow(targetIndex, newKey);
            }
            materialBrowserModal.hide();
        });

        // 開啟歷史報告 Modal (Accordion 版本)
        $('#open-history-btn').on('click', function() {
            const modalBody = $('#historyModal .modal-body');
            modalBody.html('<div class="text-center p-4"><div class="spinner-border spinner-border-sm"></div></div>');
            $.getJSON('?action=get_reports', function(reports) {
                if (!reports || reports.length === 0) {
                    modalBody.html('<div class="text-center p-4 text-muted">尚無歷史報告。</div>');
                    return;
                }
                const projects = reports.reduce((acc, report) => {
                    const projId = report.project_id || 'unassigned';
                    const projName = report.project_name || '未分類專案';
                    if (!acc[projId]) {
                        acc[projId] = { name: projName, reports: [] };
                    }
                    acc[projId].reports.push(report);
                    return acc;
                }, {});

                let accordionHtml = '<p class="text-center text-muted border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2"></i>請分別指定一份「基準(A)」與「比較(B)」報告</p>';
                accordionHtml += '<div class="accordion" id="historyAccordion">';

                Object.keys(projects).forEach(projectId => {
                    const project = projects[projectId];
                    const isUnassigned = projectId === 'unassigned';

                    accordionHtml += `
            <div class="accordion-item">
                <h2 class="accordion-header d-flex align-items-center" id="heading${projectId}">
                    <button class="accordion-button collapsed flex-grow-1" type="button" data-bs-toggle="collapse" data-bs-target="#collapse${projectId}">
                        專案：${escapeHtml(project.name)} <span class="badge bg-secondary ms-2">${project.reports.length} 個版本</span>
                    </button>
                    ${!isUnassigned ?
                        `<button class="btn btn-sm btn-outline-danger delete-project-btn me-2"
                                 data-project-id="${projectId}"
                                 data-project-name="${escapeHtml(project.name)}"
                                 title="刪除此專案及其所有報告">
                            <i class="fas fa-trash-alt"></i>
                         </button>` :
                        ''
                    }
                </h2>
                <div id="collapse${projectId}" class="accordion-collapse collapse" data-bs-parent="#historyAccordion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead><tr>
                                <th class="text-center" style="width: 80px;">基準 (A)</th>
                                <th class="text-center" style="width: 80px;">比較 (B)</th>
                                <th>版本名稱</th><th>總重(kg)</th><th>總碳排(kg CO₂e)</th><th>建立時間</th><th>操作</th>
                            </tr></thead>
                            <tbody>
                                ${project.reports.map(r => `
    <tr data-view-id="${r.view_id}">
        <td class="text-center"><input class="form-check-input" type="radio" name="baseline_select" value="${r.view_id}"></td>
        <td class="text-center"><input class="form-check-input" type="radio" name="comparison_select" value="${r.view_id}"></td>
        <td>${escapeHtml(r.version_name || '未命名版本')}</td>
        <td>${r.total_weight_kg}</td>
        <td>${r.total_co2e_kg}</td>
        <td><small>${r.created_at}</small></td>
        <td class="text-nowrap">
            <button class="btn btn-sm btn-primary load-report-btn" data-view-id="${r.view_id}" title="載入至主編輯區"><i class="fas fa-upload"></i></button>
            <button class="btn btn-sm btn-info text-white get-report-btn" data-view-id="${r.view_id}" title="檢視詳細報告書"><i class="fas fa-file-alt"></i></button>

            <button class="btn btn-sm btn-success open-tnfd-btn" data-view-id="${r.view_id}" title="開啟此版本的 TNFD 戰情室"><i class="fas fa-seedling"></i></button>
            <button class="btn btn-sm btn-danger delete-report-btn" data-id="${r.id}" title="刪除"><i class="fas fa-trash"></i></button>
        </td>
    </tr>`).join('')}
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>`;
                });

                accordionHtml += '</div>';
                modalBody.html(accordionHtml);
            }).fail(() => {
                modalBody.html('<div class="alert alert-danger">無法載入歷史報告，請檢查伺服器連線。</div>');
            });
        });

        $('#historyModal').on('click', '.load-report-btn', function() {
            const viewId = $(this).data('view-id');
            showLoading(true, '載入報告中...');
            $('#initial-message').hide();

            $.getJSON(`?action=get_report_data&view_id=${viewId}`, function(data) {
                perUnitData = data;
                const projectId = data.inputs.projectId;

                // 1. 檢查專案是否存在於主畫面的下拉選單中
                let projectOption = $(`#projectSelector option[value="${projectId}"]`);

                if (projectOption.length === 0) {
                    // 如果不存在 (通常是因為組織篩選)，則手動新增一個臨時選項
                    // 這確保了 .val(projectId) 能正確運作
                    const tempOptionText = data.inputs.newProjectName || data.versionName.split(' - ')[0] || '舊專案';
                    $('#projectSelector').append(new Option(tempOptionText, projectId));
                }

                // 2. 自動選中對應的專案
                $('#projectSelector').val(projectId);

                // 3. 【關鍵】手動觸發一次 change 事件
                //    這一步會執行我們之前寫好的邏輯，檢查 data('is-old') 並顯示遷移提示框
                $('#projectSelector').trigger('change');

                // 4. 填充其他表單資料 (邏輯不變)
                $('#versionName').val(data.versionName || data.projectName);
                $('#productionQuantity').val(data.inputs.productionQuantity || 1);
                const eol = data.inputs.eol_scenario || data.eol;
                if(eol){ $('#eolRecycle').val(eol.recycle || 100); $('#eolIncinerate').val(eol.incinerate || 0); $('#eolLandfill').val(eol.landfill || 0); }

                const container = $('#materials-list-container');
                container.empty();
                if (data.inputs.components && data.inputs.components.length > 0) {
                    data.inputs.components.forEach(c => addMaterialRow(c));
                } else {
                    addMaterialRow();
                }

                // 5. 更新UI並關閉視窗
                updateTotalWeight();
                validateEol();
                updateDashboard();
                saveState();
                $('#historyModal').modal('hide');
                $('#results-panel-wrapper').fadeIn(400);

            }).fail(() => alert('載入報告失敗: 無法從伺服器獲取數據。')).always(() => {
                showLoading(false);
                if (!perUnitData) $('#initial-message').show();
            });
        });

        $('#historyModal').on('click', '.delete-project-btn', function(e) {
            e.stopPropagation(); // 防止觸發摺疊效果
            e.preventDefault(); // 防止預設行為

            const btn = $(this);
            const projectId = btn.data('project-id');
            const projectName = btn.data('project-name');

            if (confirm(`您確定要永久刪除專案「${projectName}」嗎？\n\n警告：此操作將會一併刪除該專案下的所有分析報告，且無法復原。`)) {
                $.ajax({
                    url: '?action=delete_project',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ project_id: projectId }),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // 從畫面上移除該專案的整個區塊
                            btn.closest('.accordion-item').fadeOut(500, function() { $(this).remove(); });
                            // 可選：重新載入左側的專案下拉列表
                            $('#reloadProjectsBtn').trigger('click');
                        } else {
                            alert('刪除失敗：' + (response.message || '未知錯誤'));
                        }
                    },
                    error: function() {
                        alert('刪除專案時發生伺服器通訊錯誤。');
                    }
                });
            }
        });

        $('#historyModal').on('click', '.delete-report-btn', function() {
            const id = $(this).data('id'); const row = $(this).closest('tr');
            if(confirm('確定要永久刪除此報告？此操作無法復原。')) {
                $.ajax({
                    url: '?action=delete_report', type: 'POST', contentType: 'application/json', data: JSON.stringify({id:id}),
                    success: (res) => {
                        if(res.success) {
                            row.fadeOut(400, () => {
                                const accordionItem = row.closest('.accordion-item');
                                if(row.closest('tbody').find('tr').length === 1) { accordionItem.remove(); } else { row.remove(); }
                            });
                        } else { alert(res.message || "刪除失敗"); }
                    },
                    error: () => { alert("刪除報告時發生通訊錯誤。"); }
                });
            }
        });

        $('#historyModal').on('click', '.get-report-btn', function() {
            const viewId = $(this).data('view-id');
            if (!viewId) {
                alert('無法取得報告ID。');
                return;
            }

            showLoading(true, '正在取得數位簽章...');

            // 步驟 1: 向後端請求此報告的數位簽章
            $.getJSON(`?action=get_dpp_signature&view_id=${viewId}`)
                .done(function(response) {
                    if (response.success && response.signature) {
                        // 步驟 2: 組合包含 view_id 和簽章 (sig) 的完整安全網址
                        const secureUrl = `report.php?view_id=${viewId}&sig=${response.signature}`;

                        // 步驟 3: 開啟 Modal 並設定 iframe 的 src
                        $('#report-iframe').attr('src', secureUrl);
                        new bootstrap.Modal('#detailedReportModal').show();
                    } else {
                        alert('取得數位簽章失敗：' + (response.message || '未知錯誤'));
                    }
                })
                .fail(function() {
                    alert('與伺服器通訊失敗，無法取得數位簽章。');
                })
                .always(function() {
                    showLoading(false);
                });
        });

        $('#historyModal').on('click', '#clear-history-btn', function() {
            const confirmation = prompt("這是一個無法復原的操作！\n將會永久刪除所有專案、報告和相關檔案。\n\n若您確定要繼續，請輸入「DELETE」");
            if (confirmation === 'DELETE') {
                const $btn = $(this);
                $btn.html('<span class="spinner-border spinner-border-sm"></span> 清除中...').prop('disabled', true);
                $.ajax({
                    url: '?action=clear_all_history', type: 'GET', dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert(response.message);
                            $('#open-history-btn').trigger('click');
                        } else { alert('清除失敗：' + (response.message || '未知錯誤')); }
                    },
                    error: function() { alert('清除歷史記錄時發生伺服器通訊錯誤。'); },
                    complete: function() { $btn.html('<i class="fas fa-exclamation-triangle me-2"></i>清空所有歷史記錄').prop('disabled', false); }
                });
            } else { if(confirmation !== null) alert('操作已取消。'); }
        });

        // 動態產生的結果面板中的事件
        $(document).on('click', '#results-panel-wrapper .interpretation-icon', function() { getInterpretation($(this).data('topic'), perUnitData); });
        $(document).on('click', '#view-detailed-report-btn', function(e) {
            e.preventDefault();
            const viewId = perUnitData?.view_id;
            if (!viewId) {
                alert('請先儲存報告以產生詳細報告。');
                return;
            }

            showLoading(true, '正在取得數位簽章...');

            // 步驟 1: 向後端請求此報告的數位簽章
            $.getJSON(`?action=get_dpp_signature&view_id=${viewId}`)
                .done(function(response) {
                    if (response.success && response.signature) {
                        // 步驟 2: 組合包含 view_id 和簽章 (sig) 的完整安全網址
                        const secureUrl = `report.php?view_id=${viewId}&sig=${response.signature}`;

                        // 步驟 3: 開啟 Modal 並設定 iframe 的 src
                        $('#report-iframe').attr('src', secureUrl);
                        new bootstrap.Modal('#detailedReportModal').show();
                    } else {
                        alert('取得數位簽章失敗：' + (response.message || '未知錯誤'));
                    }
                })
                .fail(function() {
                    alert('與伺服器通訊失敗，無法取得數位簽章。');
                })
                .always(function() {
                    showLoading(false);
                });
        });


        const embedModal = new bootstrap.Modal('#embedModal');

        // 當點擊「取得內嵌碼」按鈕時
        $(document).on('click', '#embed-btn', function(e) {
            e.preventDefault();
            if (!perUnitData || !perUnitData.view_id) {
                alert('無法取得報告ID，請先「儲存報告」後再試。');
                return;
            }

            showLoading(true, '正在產生簽章...');

            // 第1步：向後端請求此報告的數位簽章
            $.getJSON(`?action=get_dpp_signature&view_id=${perUnitData.view_id}`)
                .done(function(response) {
                    if (response.success) {
                        const signature = response.signature;
                        const viewId = perUnitData.view_id;
                        const pName = $('#versionName').val() || '環保效益分析報告';
                        const toolOrigin = window.location.origin;

                        // 第2步：組合包含 view_id 和簽章 (sig) 的新 URL
                        const baseUrl = window.location.href.split('#')[0].split('?')[0];
                        const embedBaseUrl = baseUrl.substring(0, baseUrl.lastIndexOf('/') + 1) + 'embed.php';
                        const embedUrl = `${embedBaseUrl}?view_id=${viewId}&sig=${signature}`;

                        // 第3步：產生新的 iframe 和 script 程式碼
                        const iframeCode = `<iframe src="${embedUrl}" id="lca-embed-${viewId}" width="100%" frameborder="0" scrolling="no" style="border:1px solid #ccc; border-radius: 8px; width: 1px; min-width: 100%;" title="${escapeHtml(pName)}"></iframe>`;
                        const scriptCode = `<script>window.addEventListener('message', function(event) { if (event.origin !== '${toolOrigin}') return; if (event.data && event.data.type === 'iframeHeight' && event.data.viewId === '${viewId}') { var iframe = document.getElementById('lca-embed-${viewId}'); if (iframe) iframe.style.height = event.data.height + 'px'; } });<\/script>`;

                        // 第4步：將新程式碼填入 Modal 並顯示
                        $('#embed-code-iframe').val(iframeCode);
                        $('#embed-code-script').val(scriptCode);
                        embedModal.show();

                    } else {
                        alert('產生簽章失敗：' + response.message);
                    }
                })
                .fail(function() {
                    alert('與伺服器通訊失敗，無法取得簽章。');
                })
                .always(function() {
                    showLoading(false);
                });
        });

        // 「複製全部程式碼」按鈕 (邏輯不變，保持原樣)
        $(document).on('click', '#copy-embed-code', function() {
            const iframeCode = $('#embed-code-iframe').val();
            const scriptCode = $('#embed-code-script').val();
            navigator.clipboard.writeText(iframeCode + '\n\n' + scriptCode).then(() => {
                $(this).text('已複製!').removeClass('btn-primary').addClass('btn-success');
                setTimeout(() => $(this).text('複製全部程式碼').removeClass('btn-success').addClass('btn-primary'), 2000);
            });
        });


        $(document).on('click', '#export-png-btn, #export-pdf-btn', function(e) { e.preventDefault(); const isPdf = $(this).is('#export-pdf-btn'); showLoading(true, isPdf ? '正在產生PDF...' : '正在產生PNG...'); const reportElement = document.getElementById('results-panel'); html2canvas(reportElement, { scale: 2, backgroundColor: getComputedStyle(document.body).getPropertyValue('--body-bg') }).then(canvas => { if (isPdf) { const { jsPDF } = window.jspdf; const imgData = canvas.toDataURL('image/png'); const pdf = new jsPDF({ orientation: 'landscape', unit: 'px', format: [canvas.width, canvas.height] }); pdf.addImage(imgData, 'PNG', 0, 0, canvas.width, canvas.height); pdf.save(`${perUnitData.projectName || 'report'}.pdf`); } else { const link = document.createElement('a'); link.download = `${perUnitData.projectName || 'report'}.png`; link.href = canvas.toDataURL(); link.click(); } }).finally(() => showLoading(false)); });

        const templateChooserModal = new bootstrap.Modal('#template-chooser-modal');

// 當點擊「輪播報告」按鈕時
        $(document).on('click', '#view-carousel-slider-report-btn', function(e) {
            e.preventDefault();
            if (!perUnitData?.view_id) {
                alert('請先儲存報告。');
                return;
            }

            const selector = $('#carousel-template-selector');
            selector.html('<option>正在載入樣板...</option>').prop('disabled', true);
            templateChooserModal.show();

            // 向後端請求可用的樣板列表
            $.getJSON('?action=get_report_templates', function(templates) {
                selector.empty();
                if (templates && templates.length > 0) {
                    templates.forEach(template => {
                        selector.append($('<option>', {
                            value: template.file,
                            text: template.name
                        }));
                    });
                    selector.prop('disabled', false);
                } else {
                    selector.html('<option>找不到任何樣板。</option>');
                }
            }).fail(function() {
                selector.html('<option>載入樣板失敗。</option>');
            });
        });

// 當在視窗中點擊「產生報告」按鈕時
        $(document).on('click', '#generate-carousel-report-btn', function() {
            const selectedTemplate = $('#carousel-template-selector').val();
            if (perUnitData?.view_id && selectedTemplate) {
                const url = `carousel_dashboard.php?view_id=${perUnitData.view_id}&template_file=${selectedTemplate}`;
                window.open(url, '_blank');
                templateChooserModal.hide();
            } else {
                alert('請選擇一個樣板。');
            }
        });

        const qrCodeModal = new bootstrap.Modal('#qrCodeModal');

        $(document).on('click', '#share-qrcode-btn', function(e) {
            e.preventDefault();
            if (!perUnitData || !perUnitData.view_id) {
                alert('請先「儲存報告」至歷史紀錄，才能產生分享 QR Code。');
                return;
            }

            showLoading(true, '正在產生安全的 QR Code...');

            // 【修改1】向後端請求此報告的數位簽章
            $.getJSON(`?action=get_dpp_signature&view_id=${perUnitData.view_id}`)
                .done(function(response) {
                    if (response.success) {
                        const signature = response.signature;
                        const viewId = perUnitData.view_id;

                        const qrcodeContainer = $('#qrcode-display-container');
                        qrcodeContainer.empty(); // 清除舊的 QR Code

                        // 【修改2】組合包含 view_id 和簽章 (sig) 的新 embed.php 網址
                        const baseUrl = window.location.href.split('#')[0].split('?')[0];
                        const embedBaseUrl = baseUrl.substring(0, baseUrl.lastIndexOf('/') + 1) + 'embed.php';
                        const secureEmbedUrl = `${embedBaseUrl}?view_id=${viewId}&sig=${signature}`;

                        // 【修改3】使用這個新的、安全網址來產生 QR Code
                        new QRCode(document.getElementById('qrcode-display-container'), {
                            text: secureEmbedUrl,
                            width: 200,
                            height: 200,
                        });

                        qrCodeModal.show();
                    } else {
                        alert('產生簽章失敗：' + response.message);
                    }
                })
                .fail(function() {
                    alert('與伺服器通訊失敗，無法取得簽章。');
                })
                .always(function() {
                    showLoading(false);
                });
        });

        // 深度剖析模組的「上/下一步」按鈕
        $(document).on('click', '#next-act-btn', () => { if (currentAct < totalActs) showAct(currentAct + 1); });
        $(document).on('click', '#prev-act-btn', () => { if (currentAct > 1) showAct(currentAct - 1); });
        $(document).on('click', '#next-cost-act-btn', () => { if (currentCostAct < totalCostActs) showCostAct(currentCostAct + 1); });
        $(document).on('click', '#prev-cost-act-btn', () => { if (currentCostAct > 1) showCostAct(currentCostAct - 1); });
        $(document).on('click', '#next-resilience-act-btn', () => { if (currentResilienceAct < totalResilienceActs) showResilienceAct(currentResilienceAct + 1); });
        $(document).on('click', '#prev-resilience-act-btn', () => { if (currentResilienceAct > 1) showResilienceAct(currentResilienceAct - 1); });

        // 【V3.2 - 完整修正版】超級管理員 (Superadmin) 的物料庫即時編輯功能
        if (isSuperAdmin === '1') {

            // 處理一般欄位的 inline 編輯
            $('#materialsModal').on('click', '.editable', function() {
                const span = $(this);
                const field = span.data('field');

                // 如果點擊的是 sources 顯示區塊，則不觸發 inline 編輯
                if (field === 'sources-display' || field === 'sources') {
                    return;
                }

                // 如果已經在編輯模式，則取消操作
                if (span.find('input').length) return;

                const originalValue = span.text().trim();
                const key = span.data('key');

                // 建立一個與 span 同寬的 input
                const input = $('<input type="text" class="form-control form-control-sm">');
                input.val(originalValue);
                input.css('width', span.width() + 25); // 稍微加寬以容納內容
                span.html(input);
                input.focus();

                // 當 input 失去焦點時 (blur)，儲存變更
                input.on('blur', function() {
                    const newValue = $(this).val();
                    // 如果值沒有改變，則恢復原狀
                    if (newValue === originalValue) {
                        span.text(originalValue);
                        return;
                    }

                    // 顯示讀取中的 spinner
                    span.html('<span class="spinner-border spinner-border-sm" role="status"></span>');

                    const payload = {
                        original_key: key, // 傳送原始 key 以便後端定位
                        key: (field === 'key') ? newValue : key, // 如果修改的是 key 本身，則傳送新 key
                        [field]: newValue // 使用 ES6 的 computed property names
                    };

                    // 發送 AJAX 請求到後端更新
                    $.ajax({
                        url: '?action=inline_update_material',
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(payload),
                        dataType: 'json'
                    })
                        .done(function(response) {
                            // 使用我們推薦的「第一種」方法來處理回傳
                            if (response.success && response.updated_material) {
                                const updatedMaterial = response.updated_material;
                                const materialKey = updatedMaterial.key; // 使用後端回傳的最新的 key

                                // 1. 更新前端記憶體中的 ALL_MATERIALS 變數
                                const materialIndex = ALL_MATERIALS.findIndex(m => m.key === key); // 依據舊 key 尋找
                                if (materialIndex !== -1) {
                                    // 直接用後端回傳的、最準確的完整物件替換掉舊的物件
                                    ALL_MATERIALS[materialIndex] = updatedMaterial;
                                    console.log('前端物料庫已同步更新:', ALL_MATERIALS[materialIndex]);
                                }

                                // 2. 更新彈出視窗(modal)中正在編輯的那個欄位
                                span.text(newValue);
                                // 如果修改了 key，也要更新所有相關欄位的 data-key
                                if (field === 'key') {
                                    span.closest('.material-details-row').find('[data-key]').data('key', newValue).attr('data-key', newValue);
                                }

                                // 3. 同步更新主列表中的唯讀欄位，確保資料一致性
                                const mainRow = $(`.material-library-row[data-search-terms*="${key.toLowerCase()}"]`);
                                if (mainRow.length) {
                                    mainRow.find('td').eq(3).find('small').text(updatedMaterial.data_source || 'N/A');
                                    mainRow.find('td').eq(4).text(updatedMaterial.virgin_co2e_kg || '');
                                    mainRow.find('td').eq(5).text(updatedMaterial.recycled_co2e_kg || '');
                                    mainRow.find('td').eq(6).text(updatedMaterial.cost_per_kg || '');

                                    // 更新搜尋用的 data-search-terms 屬性，並增加健壯性
                                    let primary_source = updatedMaterial.data_source || 'N/A';
                                    try {
                                        if (typeof updatedMaterial.sources === 'string' && updatedMaterial.sources.trim()) {
                                            const sources_obj = JSON.parse(updatedMaterial.sources);
                                            if (sources_obj && sources_obj.primary) {
                                                primary_source = sources_obj.primary;
                                            }
                                        }
                                    } catch (e) {
                                        console.error('解析 sources 欄位時出錯:', e);
                                    }
                                    const newSearchTerms = `${updatedMaterial.name} ${updatedMaterial.key} ${updatedMaterial.category} ${primary_source}`.toLowerCase();
                                    mainRow.data('search-terms', newSearchTerms).attr('data-search-terms', newSearchTerms);
                                }

                            } else {
                                // 更新失敗，恢復原值並提示錯誤
                                alert('更新失敗: ' + (response.message || '後端未回傳有效資料。'));
                                span.text(originalValue);
                            }
                        })
                        .fail(function() {
                            alert('伺服器錯誤，請稍後再試。');
                            span.text(originalValue); // 發生錯誤，恢復原值
                        });
                });

                // 允許按 Enter 鍵儲存
                input.on('keypress', function(e) {
                    if (e.which === 13) {
                        $(this).blur();
                    }
                });
            });

            // --- 處理 sources 欄位的專屬編輯彈窗 ---
            // (這一段的程式碼是正確的，保持不變即可)
            const editSourceModal = new bootstrap.Modal('#editSourceModal');

            $('#materialsModal').on('click', '.edit-source-btn', function() {
                const key = $(this).data('key');
                const sourcesJson = $(this).data('sources-json');

                let sources = { primary: '', secondary: '', cost: '' };
                try {
                    if (typeof sourcesJson === 'string' && sourcesJson.trim() !== '') {
                        sources = JSON.parse(sourcesJson);
                    } else if (typeof sourcesJson === 'object' && sourcesJson !== null) {
                        sources = sourcesJson;
                    }
                } catch (e) {
                    console.error("解析 sources JSON 失敗:", e);
                }

                $('#edit-source-key').val(key);
                $('#edit-source-primary').val(sources.primary || '');
                $('#edit-source-secondary').val(sources.secondary || '');
                $('#edit-source-cost').val(sources.cost || '');

                editSourceModal.show();
            });

            $('#save-source-btn').on('click', function() {
                const btn = $(this);
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>儲存中...');
                const key = $('#edit-source-key').val();
                const newSources = {
                    primary: $('#edit-source-primary').val(),
                    secondary: $('#edit-source-secondary').val(),
                    cost: $('#edit-source-cost').val()
                };

                // 為了讓後端 inline_update_material 統一處理，我們構造一樣的 payload
                const payload = {
                    original_key: key,
                    key: key, // sources 編輯不改變 key
                    sources: JSON.stringify(newSources)
                };

                $.ajax({
                    url: '?action=inline_update_material', // 改用統一的 API
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    dataType: 'json'
                })
                    .done(function(response) {
                        if (response.success && response.updated_material) {
                            const updatedMaterial = response.updated_material;

                            // 更新前端 ALL_MATERIALS 變數
                            const materialIndex = ALL_MATERIALS.findIndex(m => m.key === key);
                            if (materialIndex !== -1) {
                                ALL_MATERIALS[materialIndex] = updatedMaterial;
                            }

                            const displaySpan = $(`span[data-key="${key}"][data-field="sources-display"]`);
                            const editIcon = displaySpan.next('.edit-source-btn');
                            const newDisplayHtml = `主: ${escapeHtml(newSources.primary) || 'N/A'}<br>次: ${escapeHtml(newSources.secondary) || 'N/A'}<br>成本: ${escapeHtml(newSources.cost) || 'N/A'}`;

                            displaySpan.html(newDisplayHtml);
                            editIcon.data('sources-json', JSON.stringify(newSources));

                            const mainRow = $(`.material-library-row[data-search-terms*="${key.toLowerCase()}"]`);
                            if(mainRow.length) {
                                mainRow.find('td').eq(3).find('small').text(newSources.primary || 'N/A');
                            }

                            editSourceModal.hide();
                        } else {
                            alert('更新失敗: ' + (response.message || '未知錯誤'));
                        }
                    })
                    .fail(function() {
                        alert('伺服器錯誤，請稍後再試。');
                    })
                    .always(function() {
                        btn.prop('disabled', false).text('儲存變更');
                    });
            });
        }

        // 【V3.1 - 物料庫表格互動功能：搜尋、排序、展開】
// 搜尋功能
        $('#material-library-search').on('keyup', function() {
            const searchTerm = $(this).val().toLowerCase();
            $('#materials-library-table tbody tr.material-library-row').each(function() {
                const rowTerms = $(this).data('search-terms');
                $(this).toggle(rowTerms.includes(searchTerm));
                // 確保搜尋時收起詳細資訊
                $(this).next('.material-details-row').hide();
                $(this).find('.expand-details-btn').removeClass('fa-chevron-down').addClass('fa-chevron-right');
            });
        });

// 展開/收合詳細資訊
        $('#materials-library-table').on('click', '.expand-details-btn', function() {
            const icon = $(this);
            const detailsRow = icon.closest('tr').next('.material-details-row');
            detailsRow.slideToggle(200);
            icon.toggleClass('fa-chevron-right fa-chevron-down');
        });

// 排序功能
        $('#materials-library-table').on('click', 'th.sortable', function() {
            const th = $(this);
            const sortKey = th.data('sort');
            const isAscending = th.hasClass('sort-asc');
            const tableBody = $('#materials-library-table tbody');

            // 移除其他表頭的排序指示
            th.siblings().removeClass('sort-asc sort-desc');
            // 切換目前的排序方向
            th.toggleClass('sort-asc', !isAscending).toggleClass('sort-desc', isAscending);

            const rows = tableBody.find('tr.material-library-row').get();

            rows.sort((a, b) => {
                const aData = $(a).children('td').eq(th.index()).text().trim();
                const bData = $(b).children('td').eq(th.index()).text().trim();

                const aVal = isNaN(parseFloat(aData)) ? aData.toLowerCase() : parseFloat(aData);
                const bVal = isNaN(parseFloat(bData)) ? bData.toLowerCase() : parseFloat(bData);

                if (aVal < bVal) return isAscending ? 1 : -1;
                if (aVal > bVal) return isAscending ? -1 : 1;
                return 0;
            });

            // 將排序後的 tr 和其對應的 details-row 一起重新插入
            $.each(rows, function(index, row) {
                const detailsRow = $(row).next('.material-details-row');
                tableBody.append(row);
                tableBody.append(detailsRow);
            });
        });

        $('#detailedReportModal').on('hidden.bs.modal', () => $('#report-iframe').attr('src', 'about:blank'));
        // 監聽比較視窗的關閉事件
        $(document).on('hidden.bs.modal', '#comparisonModal', function () {
            // 當比較視窗完全關閉後，為了避免焦點遺留在被隱藏的DOM中，
            // 我們手動將焦點重新設定到底下的歷史報告視窗上。
            // 這樣做可以確保螢幕閱讀器等輔助技術能正確地工作。
            const historyModalElement = document.getElementById('historyModal');
            if (historyModalElement) {
                historyModalElement.focus();
            }
        });
        // 監聽物料瀏覽器中搜尋框的即時輸入
        $(document).on('input', '#material-browser-search', function() {
            // 當使用者在搜尋框輸入任何內容時，
            // 立即呼叫 populateMaterialBrowser 函式，並將當前的搜尋文字傳遞過去
            populateMaterialBrowser($(this).val());
        });

        // 【V2.0 升級版】情境分析模組互動邏輯
        const scenarioAnalysisModal = new bootstrap.Modal('#scenarioAnalysisModal');
        let scenarioChart = null;
        let scenarioBOMCountries = [];

        function updateScenarioInputs(type) {
            const container = $('#scenario-params-container');
            const tooltipIcon = (title, content) => `<i class="fas fa-question-circle text-muted ms-auto" data-bs-toggle="popover" data-bs-trigger="hover" title="${title}" data-bs-content="${content}"></i>`;

            let targetOptionsHtml = '<option value="all">所有物料</option>';
            perUnitData.inputs.components.forEach(c => {
                const material = getMaterialByKey(c.materialKey);
                if(material) targetOptionsHtml += `<option value="${c.materialKey}">${escapeHtml(material.name)}</option>`;
            });

            if(type === 'risk'){
                targetOptionsHtml = '';
                if (scenarioBOMCountries.length > 0) {
                    scenarioBOMCountries.forEach(country => {
                        targetOptionsHtml += `<option value="${escapeHtml(country.en)}">${escapeHtml(country.zh)} (${escapeHtml(country.en)})</option>`;
                    });
                } else {
                    targetOptionsHtml = '<option value="" disabled>BOM 中無可用國家</option>';
                }
            }

            const paramTemplates = {
                cost: {
                    label: '2. 選擇作用對象',
                    target: targetOptionsHtml,
                    rangeLabel: '3. 設定成本變化範圍 (%)',
                    start: -20, end: 50, step: 10, unit: '%',
                    targetTooltip: { title: '作用對象', content: '您可以模擬「所有物料」的成本普漲/普跌，以評估總體通膨風險；或選擇「單一物料」，分析對特定關鍵零組件的依賴度。'},
                    rangeTooltip: { title: '變化範圍', content: '定義模擬的區間。例如：從 -20% (降價) 到 +50% (漲價)，每 10% 進行一次計算。這將構成結果圖表的 X 軸。'}
                },
                circularity: {
                    label: '2. 選擇作用對象',
                    target: targetOptionsHtml,
                    rangeLabel: '3. 設定再生比例絕對值 (%)',
                    start: 0, end: 100, step: 10, unit: '%',
                    targetTooltip: { title: '作用對象', content: '選擇您想評估其循環策略潛力的物料，可以是「所有物料」或「單一物料」。'},
                    rangeTooltip: { title: '再生比例區間', content: '定義要模擬的再生料比例範圍。例如：從 0% 到 100%，每 10% 進行一次計算，以找出最佳的效益平衡點。'}
                },
                risk: {
                    label: '2. 選擇風險來源國',
                    target: targetOptionsHtml,
                    rangeLabel: '3. 設定風險分數變化 (%)',
                    start: -20, end: 50, step: 10, unit: '%',
                    targetTooltip: { title: '風險來源國', content: '選擇一個您想模擬風險變化的國家。此列表僅包含您目前物料清單(BOM)中已存在的供應來源國。'},
                    rangeTooltip: { title: '風險變化範圍', content: '定義模擬的風險分數變化區間。例如：從 -20% (風險改善) 到 +50% (風險惡化)，評估對整體ESG分數的衝擊。'}
                },
                carbon_tax: {
                    label: '2. 設定碳稅價格 (TWD/kg CO₂e)',
                    target: null,
                    rangeLabel: '',
                    start: 0, end: 10, step: 1, unit: 'TWD',
                    targetTooltip: { title: '碳稅價格區間', content: '定義模擬的碳稅價格區間。例如：從 0 (無碳稅) 到 10 TWD/kg，每 1 TWD 進行一次計算，以評估不同碳價水平下的成本衝擊。'}
                }
            };

            const params = paramTemplates[type];
            let html = '';

            if(params.target){
                html += `<div class="mb-3">
                            <label class="form-label small d-flex align-items-center">${params.label} ${tooltipIcon(params.targetTooltip.title, params.targetTooltip.content)}</label>
                            <select class="form-select form-select-sm" id="scenarioTarget">${params.target}</select>
                         </div>`;
            }
            if(params.rangeLabel){
                html += `<div class="mb-3">
                            <label class="form-label small d-flex align-items-center">${params.rangeLabel} ${tooltipIcon(params.rangeTooltip.title, params.rangeTooltip.content)}</label>
                            <div class="d-flex align-items-center"><input type="number" class="form-control form-control-sm" id="scenarioStart" value="${params.start}"><span class="mx-2 small">到</span><input type="number" class="form-control form-control-sm" id="scenarioEnd" value="${params.end}"><span class="mx-2 small">間隔</span><input type="number" class="form-control form-control-sm" id="scenarioStep" value="${params.step}"></div>
                         </div>`;
            } else { // for carbon tax
                html += `<div class="mb-3">
                            <label class="form-label small d-flex align-items-center">${params.label} ${tooltipIcon(params.targetTooltip.title, params.targetTooltip.content)}</label>
                            <div class="d-flex align-items-center"><input type="number" class="form-control form-control-sm" id="scenarioStart" value="${params.start}"><span class="mx-2 small">到</span><input type="number" class="form-control form-control-sm" id="scenarioEnd" value="${params.end}"><span class="mx-2 small">間隔</span><input type="number" class="form-control form-control-sm" id="scenarioStep" value="${params.step}"><span class="input-group-text input-group-text-sm">${params.unit}</span></div>
                          </div>`;
            }
            container.html(html);
            // 重新初始化 Popover
            container.find('[data-bs-toggle="popover"]').each(function() {
                new bootstrap.Popover(this);
            });
        }

        $('#scenarioAnalysisModal').on('show.bs.modal', function() {
            if (!perUnitData) {
                alert('請先進行一次基礎分析後再使用情境模擬功能。');
                return false;
            }
            // 預先找出 BOM 表中所有國家
            const countrySet = new Set();
            perUnitData.inputs.components.forEach(c => {
                const material = getMaterialByKey(c.materialKey);
                if (material && material.country_of_origin) {
                    try {
                        const origins = JSON.parse(material.country_of_origin);
                        if(Array.isArray(origins)) {
                            origins.forEach(o => {
                                if(COUNTRY_COORDINATES[o.country]) {
                                    countrySet.add(JSON.stringify({en: o.country, zh: COUNTRY_COORDINATES[o.country].zh}));
                                }
                            });
                        }
                    } catch(e) {}
                }
            });
            scenarioBOMCountries = Array.from(countrySet).map(s => JSON.parse(s));
            // 預設選中第一個情境並更新輸入框
            $('input[name="scenario_type"][value="cost"]').prop('checked', true);
            updateScenarioInputs('cost');
        });

        $(document).on('change', 'input[name="scenario_type"]', function(){
            if(this.checked){
                updateScenarioInputs($(this).val());
            }
        });

        $('#runScenarioAnalysisBtn').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true).find('.spinner-border').removeClass('d-none');
            $('#scenario-chart-container').html('<p class="text-muted text-center pt-5">AI 正在運算多重宇宙的結果，請稍候...</p>');
            $('#scenario-interpretation-container').html('<p class="text-muted small">模擬分析的智慧解讀將顯示於此。</p>');

            const activeTab = document.querySelector('#scenarioTab .nav-link.active').id;
            let payload;

            if (activeTab === 'sensitivity-tab') {
                payload = {
                    bom: { components: perUnitData.inputs.components, eol: perUnitData.inputs.eol_scenario },
                    scenario: {
                        type: $('input[name="scenario_type"]:checked').val(),
                        target_key: $('#scenarioTarget').val(),
                        start_val: $('#scenarioStart').val(),
                        end_val: $('#scenarioEnd').val(),
                        step_val: $('#scenarioStep').val(),
                    }
                };
            } else { // financial-tab
                const exchange_rates = {};
                $('.exchange-rate-input').each(function(){
                    const currency = $(this).data('currency');
                    const rate = $(this).val();
                    if(rate && parseFloat(rate) > 0) {
                        // 前端輸入的是 1 外幣 = X 本幣, 後端需要的是 1 本幣 = Y 外幣
                        // 為方便使用者，我們在前端進行轉換
                        exchange_rates[currency] = 1 / parseFloat(rate);
                    }
                });
                const tariff_rules = [];
                $('.tariff-rule').each(function(){
                    const country = $(this).find('.tariff-country-select').val();
                    const percentage = $(this).find('.tariff-percentage-input').val();
                    if(country && percentage && parseFloat(percentage) >= 0) {
                        tariff_rules.push({ country: country, percentage: percentage });
                    }
                });
                payload = {
                    bom: { components: perUnitData.inputs.components },
                    scenario: {
                        type: 'financial',
                        base_currency: $('#baseCurrency').val(),
                        exchange_rates: exchange_rates,
                        tariff_rules: tariff_rules
                    }
                };
            }

            $.ajax({
                url: '?action=run_scenario_analysis', type: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (response.type === 'sensitivity') {
                            renderScenarioChart(response.results, payload.scenario);
                        } else if (response.type === 'financial') {
                            renderFinancialImpact(response.results, payload.scenario);
                        }
                    } else {
                        $('#scenario-chart-container').html(`<div class="alert alert-danger">${response.message}</div>`);
                    }
                },
                error: function() { $('#scenario-chart-container').html('<div class="alert alert-danger">與伺服器通訊失敗。</div>'); },
                complete: function() { btn.prop('disabled', false).find('.spinner-border').addClass('d-none'); }
            });
        });

        /**
         * [升級版] 渲染財務衝擊模擬結果 (包含瀑布圖)
         * @param {object} results - 來自後端的財務計算結果
         * @param {object} scenario - 前端發送的情境參數
         */
        function renderFinancialImpact(results, scenario) {
            // 步驟 1: 準備圖表容器，並呼叫繪圖函式
            const chartContainer = $('#scenario-chart-container');
            chartContainer.html('<canvas id="scenarioResultChart"></canvas>'); // 建立 canvas
            const chartConfig = getFinancialWaterfallChartConfig(results);

            // 銷毀舊圖表實例 (如果存在)
            if (scenarioChart) {
                scenarioChart.destroy();
            }
            const ctx = document.getElementById('scenarioResultChart').getContext('2d');
            scenarioChart = new Chart(ctx, chartConfig);

            // 步驟 2: 產生文字解讀 (與之前相同)
            const { baseline_cost, final_cost, tariff_cost, base_currency } = results;
            const impact = final_cost - baseline_cost;
            const impact_pct = baseline_cost > 0 ? (impact / baseline_cost * 100) : (final_cost > 0 ? Infinity : 0);
            const impactColor = impact >= 0 ? 'text-danger' : 'text-success';

            const formatCurrency = (value) => `${value.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} ${base_currency}`;

            const interpretationHtml = `
        <dl class="row">
            <dt class="col-sm-5">基準總成本 (換算後)</dt>
            <dd class="col-sm-7">${formatCurrency(baseline_cost)}</dd>

            <dt class="col-sm-5">關稅總成本</dt>
            <dd class="col-sm-7 text-danger fw-bold">+ ${formatCurrency(tariff_cost)}</dd>

            <hr class="my-2">

            <dt class="col-sm-5 fs-5">最終模擬總成本</dt>
            <dd class="col-sm-7 fs-5 fw-bold">${formatCurrency(final_cost)}</dd>

            <dt class="col-sm-5">成本衝擊</dt>
            <dd class="col-sm-7 fw-bold ${impactColor}">${impact >= 0 ? '+' : ''}${impact.toFixed(2)} (${isFinite(impact_pct) ? impact_pct.toFixed(1) + '%' : 'N/A'})</dd>
        </dl>
        <h6><i class="fas fa-sitemap text-success me-2"></i>策略建議</h6>
        <p class="small text-muted">
            此次模擬顯示，在您設定的匯率與關稅情境下，產品總成本將${impact_pct >=0 ? '增加' : '減少'} <strong>${Math.abs(impact_pct).toFixed(1)}%</strong>。
            其中，關稅直接導致了 <strong>${formatCurrency(tariff_cost)}</strong> 的成本增加。
            ${Math.abs(impact_pct) > 10 ? '這是一個顯著的財務風險，建議您評估供應來源多樣化的可能性，以降低對高關稅地區的依賴。' : '此成本變動在可控範圍內，但仍建議持續關注相關國家的貿易政策與匯率市場。'}
        </p>
    `;
            $('#scenario-interpretation-container').html(interpretationHtml);
        }

        /**
         * [全新] 產生財務衝擊瀑布圖的設定
         * @param {object} results - 來自後端的財務計算結果
         * @returns {object} - Chart.js 的設定物件
         */
        function getFinancialWaterfallChartConfig(results) {
            const { baseline_cost, final_cost, tariff_cost, base_currency } = results;

            const cost_after_fx = final_cost - tariff_cost;
            const fx_impact = cost_after_fx - baseline_cost;

            const labels = ['基準成本', '匯率影響', '關稅成本', '最終成本'];
            const data = [
                [0, baseline_cost], // 基準成本 (從 0 開始)
                [baseline_cost, baseline_cost + fx_impact], // 匯率影響 (從基準成本開始)
                [cost_after_fx, cost_after_fx + tariff_cost], // 關稅成本 (從匯率影響後開始)
                [0, final_cost]  // 最終成本 (從 0 開始)
            ];

            const colors = [
                'rgba(13, 110, 253, 0.7)', // 基準成本 (藍色)
                fx_impact >= 0 ? 'rgba(220, 53, 69, 0.7)' : 'rgba(25, 135, 84, 0.7)', // 匯率影響 (成本增加為紅，減少為綠)
                'rgba(220, 53, 69, 0.7)', // 關稅成本 (紅色)
                'rgba(108, 117, 125, 0.7)' // 最終成本 (灰色)
            ];

            return {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: `成本 (${base_currency})`,
                        data: data,
                        backgroundColor: colors,
                        borderColor: colors.map(c => c.replace('0.7', '1')),
                        borderWidth: 1,
                        barPercentage: 0.8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const raw = context.raw;
                                    const value = raw[1] - raw[0];
                                    const label = context.label || '';
                                    if (label === '基準成本' || label === '最終成本') {
                                        return `${label}: ${raw[1].toLocaleString(undefined, {maximumFractionDigits:2})}`;
                                    }
                                    return `${label}: ${value >= 0 ? '+' : ''}${value.toLocaleString(undefined, {maximumFractionDigits:2})}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            title: { display: true, text: `成本 (${base_currency})` }
                        }
                    }
                }
            };
        }

        function renderScenarioChart(results, scenario) {
            const container = $('#scenario-chart-container');
            container.html('<canvas id="scenarioResultChart"></canvas>');
            const ctx = document.getElementById('scenarioResultChart').getContext('2d');
            if (scenarioChart) scenarioChart.destroy();

            const paramLabels = {
                cost: { title: '成本變化 (%)', dataLabel: '總成本 (TWD)' },
                circularity: { title: '再生材料比例 (%)', dataLabel: '總碳排 (kg CO₂e)' },
                risk: { title: '風險分數變化 (%)', dataLabel: 'ESG 綜合分數' },
                carbon_tax: { title: '碳稅價格 (TWD/kg CO₂e)', dataLabel: '總成本 (TWD)' }
            };

            const datasetsConfig = {
                cost: [{ label: '總成本 (TWD)', data: results.map(r => r.total_cost), yAxisID: 'yPrimary' }],
                circularity: [
                    { label: '總碳排 (kg CO₂e)', data: results.map(r => r.total_co2), yAxisID: 'yPrimary' },
                    { label: '總成本 (TWD)', data: results.map(r => r.total_cost), yAxisID: 'ySecondary', hidden: true }
                ],
                risk: [{ label: 'ESG 綜合分數', data: results.map(r => r.esg_score), yAxisID: 'yPrimary' }],
                carbon_tax: [{ label: '總成本 (TWD)', data: results.map(r => r.total_cost), yAxisID: 'yPrimary' }]
            };

            const scalesConfig = {
                yPrimary: { type: 'linear', display: true, position: 'left', title: { display: true, text: paramLabels[scenario.type].dataLabel } },
                ySecondary: { type: 'linear', display: true, position: 'right', title: { display: true, text: '總成本 (TWD)' }, grid: { drawOnChartArea: false } }
            };

            scenarioChart = new Chart(ctx, {
                type: 'line',
                data: { labels: results.map(r => r.step), datasets: datasetsConfig[scenario.type] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { x: { title: { display: true, text: paramLabels[scenario.type].title }}, ...scalesConfig },
                    interaction: { mode: 'index', intersect: false },
                }
            });
            generateScenarioInterpretation(results, scenario);
        }

        /**
         * 【全新增強版 v2.0】智慧解讀函式
         * @param {Array} results - 來自後端的模擬結果陣列
         * @param {Object} scenario - 前端發送的模擬情境參數
         */
        function generateScenarioInterpretation(results, scenario) {
            const container = $('#scenario-interpretation-container');
            if (!results || results.length < 2) {
                container.html('<p class="text-muted small">數據點不足，無法進行趨勢分析。</p>'); return;
            }

            let insights = [], strategies = [];
            const startPoint = results[0];
            const endPoint = results[results.length - 1];
            let targetName;
            if(scenario.type === 'risk'){
                targetName = scenario.target_key === 'all' ? '所有國家' : (scenarioBOMCountries.find(c=>c.en === scenario.target_key)?.zh || '目標國家');
            } else {
                targetName = scenario.target_key === 'all' ? '所有物料' : (getMaterialByKey(scenario.target_key)?.name || '目標物料');
            }

            switch(scenario.type){
                case 'cost':
                    const baseCost = perUnitData.impact.cost;
                    const costChange = endPoint.total_cost - startPoint.total_cost;
                    const paramChange = endPoint.step - startPoint.step;
                    if (Math.abs(costChange) > 0.01 && paramChange !== 0) {
                        const costImpactPerParam = costChange / paramChange;
                        insights.push(`當 <strong>${escapeHtml(targetName)}</strong> 的單位成本每變化 <strong>1%</strong>，產品總成本約變化 <strong>${costImpactPerParam.toFixed(3)} 元</strong>。`);
                        if (baseCost > 0.01) {
                            const sensitivity = (costImpactPerParam * 100) / baseCost;
                            strategies.push(`您的產品成本對「${escapeHtml(targetName)}」的價格變動 <strong>${Math.abs(sensitivity) > 1 ? '高度敏感' : '關聯性中等'}</strong> (敏感度係數: ${sensitivity.toFixed(2)})。建議管理此物料的採購風險。`);
                        }
                    }
                    break;
                case 'circularity':
                    const baseCo2 = perUnitData.impact.co2;
                    const co2Change = endPoint.total_co2 - startPoint.total_co2;
                    const recycledChange = endPoint.step - startPoint.step;
                    if (recycledChange > 0 && co2Change < 0) {
                        const co2ReductionPerPercent = -co2Change / recycledChange;
                        insights.push(`在此模擬區間內，針對 <strong>${escapeHtml(targetName)}</strong>，再生比例每提高 <strong>1%</strong>，約可帶來 <strong>${co2ReductionPerPercent.toFixed(4)} kg</strong> 的碳排減量，這顯示了良好的「<strong>生態效益</strong>」。`);
                    }
                    const minCo2Point = results.reduce((min, p) => p.total_co2 < min.total_co2 ? p : min, results[0]);
                    strategies.push(`策略建議：當 <strong>${escapeHtml(targetName)}</strong> 的再生材料比例達到 <strong>${minCo2Point.step}%</strong> 時，可獲得此模擬區間內的最低總碳足跡 (<strong>${minCo2Point.total_co2.toFixed(3)} kg CO₂e</strong>)。`);
                    break;
                case 'risk':
                    const esgChange = endPoint.esg_score - startPoint.esg_score;
                    const riskParamChange = endPoint.step - startPoint.step;
                    if(riskParamChange !== 0){
                        const esgImpactPerRisk = esgChange / riskParamChange;
                        insights.push(`當來自 <strong>${escapeHtml(targetName)}</strong> 的供應鏈風險每變動 <strong>1%</strong>，產品的綜合 ESG 分數約變動 <strong>${esgImpactPerRisk.toFixed(3)}</strong> 分。`);
                    }
                    strategies.push(`此模擬顯示了您的產品 ESG 表現對特定國家地緣政治風險的<span class="highlight-term">脆弱性</span>。若分數變化劇烈，代表您應考慮分散來自 <strong>${escapeHtml(targetName)}</strong> 的採購，以提升供應鏈韌性。`);
                    break;
                case 'carbon_tax':
                    const costChangeWithTax = endPoint.total_cost - startPoint.total_cost;
                    const taxChange = endPoint.step - startPoint.step;
                    if(taxChange !== 0){
                        const costImpactPerTax = costChangeWithTax / taxChange;
                        insights.push(`在目前的設計下，碳稅價格每增加 <strong>1 TWD/kg CO₂e</strong>，您的產品總成本將增加約 <strong>${costImpactPerTax.toFixed(2)} 元</strong>。`);
                    }
                    strategies.push(`這個數字是您的「<span class="highlight-term">碳成本敞口</span>」，是衡量未來氣候相關財務風險的關鍵指標。若要降低此風險，核心策略是降低產品的總碳足跡。`);
                    break;
            }

            let html = `<h6><i class="fas fa-search-plus text-info me-2"></i>核心發現</h6><ul class="list-group list-group-flush small mb-3">${insights.map(i => `<li class="list-group-item p-2">${i}</li>`).join('')}</ul>`;
            if (strategies.length > 0) {
                html += `<h6><i class="fas fa-sitemap text-success me-2"></i>策略建議</h6><ul class="list-group list-group-flush small">${strategies.map(s => `<li class="list-group-item p-2">${s}</li>`).join('')}</ul>`;
            }
            container.html(html);
        }

        $(document).on('mouseenter', '.legend-item', function() {
            const categoryClass = $(this).data('category-class');
            if (!categoryClass) return;

            // 將所有標記加上淡化效果
            $('.pie-marker-icon').addClass('marker-fade');

            // 找出符合類別的標記，移除淡化並加上高亮效果
            $(`.pie-marker-icon.${categoryClass}`).removeClass('marker-fade').addClass('marker-highlight');
        });

        // --- [新增] 多版本比較輪播選擇器的完整邏輯 ---

        const reportChooserModal = new bootstrap.Modal(document.getElementById('reportChooserModal'));

        // 當點擊主畫面上的「產生比較輪播」按鈕時
        $(document).on('click', '#open-report-chooser-btn', function() {
            const listContainer = $('#report-chooser-list-container');
            listContainer.html('<div class="text-center p-4"><div class="spinner-border spinner-border-sm"></div> 載入歷史報告中...</div>');

            // 從後端獲取所有報告列表
            $.getJSON('?action=get_reports', function(reports) {
                if (!reports || reports.length === 0) {
                    listContainer.html('<div class="alert alert-warning">沒有可供比較的歷史報告。請先儲存一些分析版本。</div>');
                    return;
                }

                // 按專案分組報告
                const projects = reports.reduce((acc, report) => {
                    const projName = report.project_name || '未分類專案';
                    if (!acc[projName]) acc[projName] = [];
                    acc[projName].push(report);
                    return acc;
                }, {});

                let chooserHtml = '<ul class="list-group">';
                for (const projectName in projects) {
                    chooserHtml += `<li class="list-group-item list-group-item-dark disabled">${projectName}</li>`;
                    projects[projectName].forEach(r => {
                        chooserHtml += `
                        <li class="list-group-item">
                            <input class="form-check-input me-2" type="checkbox" value="${r.view_id}" id="report_check_${r.id}">
                            <label class="form-check-label stretched-link" for="report_check_${r.id}">
                                ${escapeHtml(r.version_name || '未命名版本')}
                                <small class="text-muted d-block">${r.created_at}</small>
                            </label>
                        </li>`;
                    });
                }
                chooserHtml += '</ul>';
                listContainer.html(chooserHtml);

                // 重置計數器和按鈕狀態
                $('#selection-counter').text('已選擇 0 份報告');
                $('#generate-comparison-carousel-btn').prop('disabled', true);

            }).fail(() => {
                listContainer.html('<div class="alert alert-danger">載入報告列表失敗。</div>');
            });

            reportChooserModal.show();
        });

        /**
         * 【V1.1 錯誤修正版】前端財務儀表板總渲染器
         * @description 修正了錯誤的函式呼叫，確保能正確渲染所有財務儀表板。
         */
        function renderAllFinancialDashboards(commercialData, fullReportData) {
            // --- Part 1: 渲染「商業決策儀表板」---
            // 【核心修正】呼叫正確的 V2 函式
            const commercialCardHtml = generateCommercialCardHTML_V2(commercialData);
            $('#commercial-benefits-container').html(commercialCardHtml);
            drawCostBreakdownDoughnutChart(commercialData.total_cost_breakdown);
            $('#commercial-benefits-container [data-bs-toggle="tooltip"]').tooltip();

            // --- Part 2: 渲染「綜合財務風險儀表板」---
            const { regulatory_impact, financial_risk_at_risk } = fullReportData;
            const cbam_cost = regulatory_impact?.cbam_cost_twd ?? 0;
            const plastic_tax_cost = regulatory_impact?.plastic_tax_twd ?? 0;
            const tnfd_var = financial_risk_at_risk?.value_at_risk ?? 0;
            const green_premium = (commercialData.green_premium_per_unit > 0) ? commercialData.green_premium_per_unit * commercialData.quantity : 0;
            const total_exposure = cbam_cost + plastic_tax_cost + tnfd_var + green_premium;
            const material_cost = (fullReportData.impact.cost ?? 0) * (commercialData.quantity ?? 1);

            let risk_score = (material_cost > 0) ? (total_exposure / material_cost * 100) : 0;
            risk_score = Math.min(100, Math.round(risk_score));
            const scoreColor = risk_score >= 50 ? 'danger' : (risk_score >= 25 ? 'warning' : 'success');

            let exposure_as_pct_of_profit = 'N/A';
            if (commercialData.total_net_profit > 0) {
                exposure_as_pct_of_profit = ((total_exposure / commercialData.total_net_profit) * 100).toFixed(1) + '%';
            } else if (commercialData.total_net_profit <= 0) {
                exposure_as_pct_of_profit = '<span class="text-danger">虧損中</span>';
            }

            let risk_details = [
                {label: '歐盟 CBAM', value: cbam_cost}, {label: '歐盟塑膠稅', value: plastic_tax_cost},
                {label: '自然相關風險(VaR)', value: tnfd_var}, {label: '綠色材料溢價', value: green_premium}
            ].filter(item => item.value > 0).sort((a,b) => b.value - a.value);

            const top_risk_source = risk_details.length > 0 ? risk_details[0].label : '無';

            // 【核心修正】呼叫我們剛剛新增的前端函式
            const financialRiskCardHtml = generate_financial_risk_summary_html(
                total_exposure, risk_score, scoreColor, exposure_as_pct_of_profit, top_risk_source, risk_details
            );
            $('#financial-risk-summary-container').html(financialRiskCardHtml);
            if (risk_details.length > 0) {
                drawFinancialSummaryChart(risk_details);
            }

            // --- Part 3: 渲染「成本效益深度剖析」---
            const costDeepDiveHtml = generateCostBenefitDeepDiveHtml();
            $('#cost-benefit-deep-dive-container').html(costDeepDiveHtml);
            generateCostBenefitNarratives_Expert(fullReportData);
            drawCostCompositionChart(prepareCostCompositionData(fullReportData.charts.composition));
            drawCostCarbonChart(prepareCostCarbonData(fullReportData.charts.composition));
            drawCostComparisonChart(prepareCostComparisonData(fullReportData.charts.composition));
            showCostAct(1);
        }

        /**
         * 【V4.1 最終確認版】前端即時商業效益計算引擎
         * @description 確認呼叫的是最新且正確的總渲染器。
         */
        function recalculateAndDisplayCommercialBenefits() {
            if (!perUnitData || !perUnitData.impact || !perUnitData.virgin_impact) { return; }

            const sellingPrice = parseFloat($('#sellingPriceInput').val()) || 0;

            if (sellingPrice <= 0) {
                $('#commercial-benefits-container, #financial-risk-summary-container, #cost-benefit-deep-dive-container').empty().hide();
                return;
            }

            $('#commercial-benefits-container, #financial-risk-summary-container, #cost-benefit-deep-dive-container').show();

            const manufacturing_cost = parseFloat($('#manufacturingCostInput').val()) || 0;
            const sga_cost = parseFloat($('#sgaCostInput').val()) || 0;
            const other_costs_per_unit = manufacturing_cost + sga_cost;
            const quantity = parseInt($('#productionQuantity').val()) || 1;
            const actual_material_cost_per_unit = perUnitData.impact.cost;
            const actual_total_cost_per_unit = actual_material_cost_per_unit + other_costs_per_unit;
            const benchmark_material_cost_per_unit = perUnitData.virgin_impact.cost;
            const actual_co2_per_unit = perUnitData.impact.co2;
            const net_profit_per_unit = sellingPrice - actual_total_cost_per_unit;
            const green_premium_per_unit = actual_material_cost_per_unit - benchmark_material_cost_per_unit;
            const benchmark_profit_per_unit = sellingPrice - (benchmark_material_cost_per_unit + other_costs_per_unit);
            const net_margin = (sellingPrice > 0) ? (net_profit_per_unit / sellingPrice) * 100 : 0;
            const profit_per_co2 = (actual_co2_per_unit != 0) ? (net_profit_per_unit / actual_co2_per_unit) : null;

            const commercial_data = {
                quantity: quantity,
                total_revenue: sellingPrice * quantity,
                total_net_profit: net_profit_per_unit * quantity,
                net_margin: net_margin,
                green_premium_per_unit: green_premium_per_unit,
                net_profit_per_unit: net_profit_per_unit,
                benchmark_profit_per_unit: benchmark_profit_per_unit,
                profit_per_co2: profit_per_co2,
                total_cost_breakdown: {
                    material: actual_material_cost_per_unit * quantity,
                    manufacturing: manufacturing_cost * quantity,
                    sga: sga_cost * quantity,
                }
            };

            renderAllFinancialDashboards(commercial_data, perUnitData);
        }

        /**
         * 【V3.1 KPI UI 專家版 - 原名取代】前端專用的「商業決策儀表板」HTML產生器
         * @description 採用 KPI + 細項分析的專業佈局，並新增成本結構環圈圖。
         */
        function generateCommercialCardHTML_V2(data) {
            const { quantity, total_revenue, total_net_profit, net_margin, green_premium_per_unit, net_profit_per_unit, benchmark_profit_per_unit, profit_per_co2, total_cost_breakdown } = data;

            // --- (數據格式化與 KPI 區塊邏輯保持不變) ---
            const fmt = (val, dec = 2) => val.toLocaleString(undefined, {minimumFractionDigits: dec, maximumFractionDigits: dec});
            const profit_delta = net_profit_per_unit - benchmark_profit_per_unit;
            const profitDeltaHtml = (profit_delta >= 0) ? `<span class='text-success'>+${fmt(profit_delta)}</span>` : `<span class='text-danger'>-${fmt(Math.abs(profit_delta))}</span>`;
            const kpis = [
                { label: '總淨利貢獻', value: `${fmt(total_net_profit, 0)} <small class='text-muted'>TWD</small>`, color: 'text-primary' },
                { label: '淨利率', value: `${net_margin.toFixed(1)}<small class='text-muted'>%</small>`, color: 'text-primary' },
                { label: '對淨利的影響', value: `${profitDeltaHtml} <small class='text-muted'>/件</small>`, color: '' },
                { label: '碳—淨利效率', value: `${profit_per_co2 === null ? 'N/A' : fmt(profit_per_co2)}`, color: 'text-primary' }
            ];
            const kpiHtml = kpis.map(kpi => `<div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100 text-center"><h6 class="text-muted small">${kpi.label}</h6><p class="fs-4 fw-bold ${kpi.color} mb-0">${kpi.value}</p></div></div>`).join('');
            const totalCost = total_cost_breakdown.material + total_cost_breakdown.manufacturing + total_cost_breakdown.sga;
            const costTableRows = `
        <tr><td>材料成本</td><td class="text-end">${fmt(total_cost_breakdown.material / quantity)}</td><td class="text-end fw-bold">${fmt(total_cost_breakdown.material)}</td></tr>
        <tr><td>製造成本</td><td class="text-end">${fmt(total_cost_breakdown.manufacturing / quantity)}</td><td class="text-end fw-bold">${fmt(total_cost_breakdown.manufacturing)}</td></tr>
        <tr><td>管銷/其他</td><td class="text-end">${fmt(total_cost_breakdown.sga / quantity)}</td><td class="text-end fw-bold">${fmt(total_cost_breakdown.sga)}</td></tr>
        <tr class="table-light"><td class="fw-bold">總成本</td><td class="text-end fw-bold">${fmt(totalCost / quantity)}</td><td class="text-end fw-bold fs-5">${fmt(totalCost)}</td></tr>
    `;
            const premium_label = (green_premium_per_unit >= 0) ? "綠色材料溢價" : "綠色材料折扣";
            const premium_html = (green_premium_per_unit >= 0) ? `<span class='text-danger'>${fmt(green_premium_per_unit)}</span>` : `<span class='text-success'>${fmt(Math.abs(green_premium_per_unit))}</span>`;
            let insight = '';
            if (net_profit_per_unit < 0) { insight = `<strong>策略警示：</strong>在計入所有成本後，此產品處於<strong class="text-danger">虧損狀態</strong>。您的定價策略無法覆蓋永續投入與其他營運成本，急需重新評估。`; }
            else if (profit_delta < 0) { insight = `<strong>策略權衡：</strong>您的永續策略雖然成功，但相較於傳統設計，每件產品的<strong class="text-warning">淨利貢獻減少了 ${fmt(Math.abs(profit_delta))} 元</strong>。這是一個明確的「權衡取捨」，您需要確保品牌的綠色價值能彌補此利潤差距。`; }
            else { insight = `<strong>策略雙贏：</strong>恭喜！您的永續策略是一項成功的商業決策。相較於傳統設計，您的<strong class="text-success">淨利貢獻每件增加了 ${fmt(profit_delta)} 元</strong>，實現了「獲利能力」與「環境效益」的雙贏。`; }

            // 【修改處】新增 SDG 圖示
            const sdgHtml = generateSdgIconsHtml([8, 9, 12]);
            return `
    <div class="card h-100 shadow-sm animate__animated animate__fadeInUp"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas fa-chart-line text-primary me-2"></i>商業決策儀表板<span class="badge bg-primary-subtle text-primary-emphasis ms-2">財務 (F)</span></h5>${sdgHtml}<i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="commercial-dashboard-v2" title="這代表什麼？"></i></div>
        <div class="card-body">
            <div class="row g-3 text-center mb-4">${kpiHtml}</div><hr>
            <div class="row g-4 mt-1">
                <div class="col-lg-6">
                    <h6 class="text-muted">成本結構分析 (共 ${quantity.toLocaleString()} 件)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light"><tr><th>成本項目</th><th class="text-end">單件成本 (TWD)</th><th class="text-end">總成本 (TWD)</th></tr></thead>
                            <tbody>${costTableRows}</tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-6">
                     <h6 class="text-muted">成本佔比視覺化</h6>
                     <div style="height: 120px; position: relative;"><canvas id="costBreakdownDoughnutChart"></canvas></div>
                     <h6 class="text-muted mt-3">永續策略的財務影響 (單件)</h6>
                     <div class="row g-2">
                        <div class="col-6"><div class="p-2 bg-light-subtle rounded-3 h-100 text-center"><div class="small">${premium_label}</div><div class="fw-bold fs-5">${premium_html}</div></div></div>
                        <div class="col-6"><div class="p-2 bg-light-subtle rounded-3 h-100 text-center"><div class="small">對淨利貢獻的影響</div><div class="fw-bold fs-5">${profitDeltaHtml}</div></div></div>
                     </div>
                </div>
            </div>
            <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察：我的永續策略划算嗎？</h6><p class="small text-muted mb-0">${insight}</p></div>
        </div>
    </div>`;
        }

        /**
         * 【V4.1 JS 移植版】產生綜合財務風險儀表板的 HTML (前端版本)
         * @description 將後端 PHP 的 V4.0 KPI 專家版邏輯完整移植到前端。
         */
        function generate_financial_risk_summary_html(total_exposure, risk_score, scoreColor, exposure_as_pct_of_profit, top_risk_source, risk_details) {
            const total_exposure_formatted = total_exposure.toLocaleString(undefined, {maximumFractionDigits: 0});

            const kpis = [
                { label: '總曝險金額', value: `${total_exposure_formatted} <small class='text-muted'>TWD</small>`, color: 'text-danger' },
                { label: '財務風險總分', value: `${risk_score} <small class='text-muted'>/ 100</small>`, color: `text-${scoreColor}` },
                { label: '曝險佔淨利比', value: exposure_as_pct_of_profit, color: '' },
                { label: '最大風險來源', value: top_risk_source, color: 'text-primary' }
            ];

            const kpiHtml = kpis.map(kpi => `
                <div class="col-md-3"><div class="p-2 bg-light-subtle rounded-3 h-100 text-center"><h6 class="text-muted small">${kpi.label}</h6><p class="fs-4 fw-bold ${kpi.color} mb-0">${kpi.value}</p></div></div>
            `).join('');

            let risk_details_html = '';
            if (risk_details.length === 0) {
                risk_details_html = '<tr><td colspan="3" class="text-center text-muted">無顯著風險項目</td></tr>';
            } else {
                risk_details.forEach(item => {
                    const percentage = (total_exposure > 0) ? (item.value / total_exposure * 100) : 0;
                    risk_details_html += `<tr>
                        <td>${escapeHtml(item.label)}</td>
                        <td class="text-end fw-bold">${item.value.toLocaleString(undefined, {maximumFractionDigits: 0})}</td>
                        <td class="text-end">${percentage.toFixed(1)}%</td>
                    </tr>`;
                });
            }

            let insight = `<strong>風險剖析：</strong>恭喜！目前產品未識別出顯著的永續相關財務風險，展現了卓越的財務韌性。`;
            let advice = `<strong>行動建議：</strong>請將此低財務風險的特性，作為您產品的關鍵競爭優勢，並在與投資人或客戶溝通時加以強調。`;
            if (total_exposure > 0) {
                insight = `<strong>風險剖析：</strong>產品的永續相關財務總曝險約為 <strong>${total_exposure_formatted} TWD</strong>，可能侵蝕掉 <strong>${exposure_as_pct_of_profit}</strong> 的產品淨利。`;
                advice = `<strong>行動建議：</strong>您的財務團隊應優先針對最大風險來源「<strong>${escapeHtml(top_risk_source)}</strong>」制定緩解策略，以保護產品的獲利能力。`;
            }

            return `
            <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas fa-coins text-primary me-2"></i>綜合財務風險儀表板<span class="badge bg-primary-subtle text-primary-emphasis ms-2">財務 (F)</span></h5><i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="financial-risk-summary-dashboard" title="這代表什麼？"></i></div>
                <div class="card-body">
                    <div class="row g-3 text-center mb-4">${kpiHtml}</div><hr>
                    <div class="row g-4 mt-1">
                        <div class="col-lg-6">
                            <h6 class="text-muted">風險細項分析 (金額與佔比)</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead class="table-light"><tr><th>風險來源</th><th class="text-end">曝險金額 (TWD)</th><th class="text-end">佔比</th></tr></thead>
                                    <tbody>${risk_details_html}</tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <h6 class="text-muted">曝險金額比較 (視覺化)</h6>
                            <div style="height: 220px;"><canvas id="financialRiskSummaryChart"></canvas></div>
                        </div>
                    </div>
                    <hr class="my-3"><div class="p-3 bg-light-subtle rounded-3"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察</h6><p class="small text-muted mb-1">${insight}</p><p class="small text-muted mb-0">${advice}</p></div>
                </div>
            </div>`;
        }

        /**
         * 【V2.2 專家升級版】前端專用的「商業決策儀表板」HTML產生器
         * @description AI 智慧洞察文字升級，提供更具體的「策略警示/權衡/雙贏」診斷。
         */
        function generateCommercialCardHTML_JS(data) {
            const quantity_fmt = data.quantity.toLocaleString();
            const total_revenue_fmt = data.total_revenue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits:2});
            const total_material_cost_fmt = data.total_actual_cost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits:2});
            const total_gross_profit_fmt = data.total_gross_profit.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits:2});
            const gross_margin_fmt = data.gross_margin.toFixed(1);
            const green_premium_fmt = Math.abs(data.green_premium_per_unit).toLocaleString(undefined, {maximumFractionDigits:2});
            const profit_delta = data.gross_profit_per_unit - data.benchmark_profit_per_unit;
            const profit_delta_fmt = Math.abs(profit_delta).toLocaleString(undefined, {maximumFractionDigits:2});
            const profit_per_co2_fmt = data.profit_per_co2 === null ? 'N/A' : data.profit_per_co2.toLocaleString(undefined, {maximumFractionDigits:2});
            const premium_html = (data.green_premium_per_unit >= 0) ? `<span class='text-danger'>${green_premium_fmt}</span> TWD/件` : `<span class='text-success'>${green_premium_fmt}</span> TWD/件`;
            const premium_label = (data.green_premium_per_unit >= 0) ? "綠色材料溢價" : "綠色材料折扣";
            const profit_delta_html = (profit_delta >= 0) ? `<span class='text-success'>+${profit_delta_fmt}</span> TWD/件` : `<span class='text-danger'>-${profit_delta_fmt}</span> TWD/件`;

            // 【核心升級】AI 智慧洞察文字
            let insight = '';
            if (data.green_premium_per_unit > 0 && data.gross_profit_per_unit < 0) {
                insight = `<strong>策略警示：</strong>您為永續付出的「綠色材料溢價」已<strong class="text-danger">侵蝕掉所有毛利貢獻</strong>，此產品在支付材料費用後已無任何利潤空間。必須立即重新評估定價策略或尋求成本更低的綠色方案。`;
            } else if (profit_delta < 0) {
                insight = `<strong>策略權衡：</strong>您的永續策略雖然成功，但相較於傳統設計，每件產品的<strong class="text-warning">毛利貢獻減少了 ${profit_delta_fmt} 元</strong>。這是一個明確的「權衡取捨」，您需要確保品牌的綠色價值能彌補此利潤差距。`;
            } else {
                insight = `<strong>策略雙贏：</strong>恭喜！您的永續策略是一項成功的商業決策。相較於傳統設計，您的<strong class="text-success">毛利貢獻每件增加了 ${profit_delta_fmt} 元</strong>，實現了「獲利潛力」與「環境效益」的雙贏。`;
            }

            return `
    <div class="card h-100 shadow-sm animate__animated animate__fadeInUp"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0 d-flex align-items-center"><i class="fas fa-chart-line text-primary me-2"></i>商業決策儀表板<span class="badge bg-primary-subtle text-primary-emphasis ms-2">財務 (F)</span></h5><i class="fas fa-comment-dots ms-2 interpretation-icon" data-topic="commercial-dashboard" title="這代表什麼？" style="cursor: pointer; opacity: 0.8;"></i></div>
        <div class="card-body"><div class="row g-4"><div class="col-lg-4 border-end"><h6 class="text-muted">總效益 (共 ${quantity_fmt} 件)</h6><dl class="row"><dt class="col-6">總營收</dt><dd class="col-6 text-end fw-bold">${total_revenue_fmt} TWD</dd><dt class="col-6">材料總成本</dt><dd class="col-6 text-end fw-bold">${total_material_cost_fmt} TWD</dd><dt class="col-6">毛利貢獻</dt><dd class="col-6 text-end fw-bold text-primary fs-5">${total_gross_profit_fmt} TWD</dd><dt class="col-6">毛利貢獻率</dt><dd class="col-6 text-end fw-bold">${gross_margin_fmt}%</dd></dl></div>
                <div class="col-lg-4 border-end"><h6 class="text-muted">永續策略的財務影響 (單件)</h6><div class="d-flex justify-content-between align-items-center my-2"><span>${premium_label}</span><span class="badge fs-6 bg-light text-dark p-2">${premium_html}</span></div><div class="d-flex justify-content-between align-items-center my-2"><span>對毛利貢獻的影響</span><span class="badge fs-6 bg-light text-dark p-2">${profit_delta_html}</span></div></div>
                <div class="col-lg-4"><h6 class="text-muted">進階效率指標</h6><div class="text-center p-3 bg-light-subtle rounded-3 mt-2"><h6 class="text-muted small">碳—收益效率</h6><p class="display-6 fw-bold mb-0 text-primary">${profit_per_co2_fmt}</p><small class="text-muted">(毛利貢獻 / kg CO₂e)</small></div></div></div>
            <hr><div class="p-3 bg-light-subtle rounded-3"><h6 class="small fw-bold"><i class="fas fa-lightbulb text-primary me-2"></i>AI 智慧洞察：我的永續策略划算嗎？</h6><p class="small text-muted mb-0">${insight}</p></div></div></div>`;
        }

        // 【全新】為生產數量與售價輸入框綁定即時更新事件
        $(document).on('input', '#productionQuantity, #sellingPriceInput, #manufacturingCostInput, #sgaCostInput', function() {
            recalculateAndDisplayCommercialBenefits();
        });

        // 監聽選擇器內部複選框的變化
        $('#reportChooserModal').on('change', '.form-check-input', function() {
            const checkedCount = $('#reportChooserModal .form-check-input:checked').length;
            $('#selection-counter').text(`已選擇 ${checkedCount} 份報告`);
            $('#generate-comparison-carousel-btn').prop('disabled', checkedCount < 2);
        });

        /**
         * 產生 GRI 報告摘要
         */
        function generateGRIReportSummary() {
            if (!perUnitData) return "";
            const { impact, inputs, charts, circularity_analysis, social_impact, governance_impact } = perUnitData;
            const quantity = inputs.productionQuantity || 1;
            let summary = `===== GRI 準則數據摘要 - ${escapeHtml(perUnitData.versionName)} =====\n\n`;
            summary += `報告期間：${new Date().toISOString().split('T')[0]}\n`;
            summary += `涵蓋範疇：單一產品生命週期評估\n\n`;
            summary += `--- GRI 301: 物料 (Materials) ---\n`;
            summary += `GRI 301-1: 所用物料及其重量\n - 總重量: ${(inputs.totalWeight * quantity).toFixed(3)} kg\n`;
            summary += `GRI 301-2: 使用的回收再生進料\n - 再生材料總重量: ${(charts.content_by_type.recycled * quantity).toFixed(3)} kg\n - 再生材料總比例: ${((charts.content_by_type.recycled / inputs.totalWeight) * 100).toFixed(1)} %\n\n`;
            summary += `--- GRI 302: 能源 (Energy) ---\n`;
            summary += `GRI 302-1: 組織內部能源消耗\n - 總能源消耗: ${(impact.energy * quantity).toFixed(2)} MJ\n`;
            summary += `GRI 302-3: 能源密集度\n - 單位產品能耗: ${impact.energy.toFixed(2)} MJ/件\n\n`;
            summary += `--- GRI 303: 水與放流水 (Water and Effluents) ---\n`;
            summary += `GRI 303-3: 取水\n - 總取水量: ${(impact.water_withdrawal * quantity).toFixed(4)} m³\n`;
            summary += `GRI 303-4: 放水\n - 總廢水排放: ${(impact.wastewater * quantity).toFixed(4)} m³\n`;
            summary += `GRI 303-5: 用水\n - 總水資源消耗: ${(impact.water * quantity).toFixed(2)} L\n\n`;
            summary += `--- GRI 305: 排放 (Emissions) ---\n`;
            summary += `GRI 305-1, 305-2: 溫室氣體排放 (範疇1+2)\n - 生產製造階段總碳排: ${(charts.lifecycle_co2.production * quantity).toFixed(3)} kg CO₂e\n`;
            summary += `GRI 305-4: 溫室氣體排放密集度\n - 單位產品碳排: ${impact.co2.toFixed(3)} kg CO₂e/件\n\n`;
            summary += `--- GRI 306: 廢棄物 (Waste) ---\n`;
            summary += `GRI 306-3: 所產生的廢棄物\n - 總生產廢棄物: ${(impact.waste * quantity).toFixed(3)} kg\n`;
            return summary.trim();
        }

        /**
         * 產生 SASB 硬體產業報告摘要
         */
        function generateSASBReportSummary() {
            if (!perUnitData) return "";
            const { impact, virgin_impact, inputs, circularity_analysis, social_impact, governance_impact, water_scarcity_impact } = perUnitData;
            let summary = `===== SASB - 技術與通訊 - 硬體產業 (TC-HW) =====\n\n`;
            summary += `報告實體：${escapeHtml(perUnitData.versionName)}\n`;
            summary += `報告日期：${new Date().toISOString().split('T')[0]}\n\n`;
            summary += `--- 溫室氣體排放 (TC-HW-110a.1) ---\n`;
            summary += `總溫室氣體排放 (範疇1+2): ${impact.co2.toFixed(3)} kg CO₂e/件\n`;
            summary += `基準 (100%原生料) 排放: ${virgin_impact.co2.toFixed(3)} kg CO₂e/件\n\n`;
            summary += `--- 水資源管理 (TC-HW-140a.1) ---\n`;
            summary += `總取水量: ${impact.water_withdrawal.toFixed(4)} m³/件\n`;
            summary += `總用水量: ${(impact.water / 1000).toFixed(4)} m³/件\n`;
            summary += `水資源短缺 (AWARE) 足跡: ${water_scarcity_impact.total_impact_m3_world_eq.toFixed(3)} m³ eq./件\n\n`;
            summary += `--- 廢棄物管理 (TC-HW-150a.1) ---\n`;
            summary += `生命週期前期總廢棄物: ${impact.waste.toFixed(3)} kg/件\n`;
            summary += `產品生命終端回收率目標: ${inputs.eol_scenario.recycle} %\n\n`;
            summary += `--- 供應鏈管理 (TC-HW-430b.1) ---\n`;
            summary += `供應鏈社會(S)風險分數: ${social_impact.overall_risk_score.toFixed(1)} / 100 (越高越差)\n`;
            summary += `供應鏈治理(G)風險分數: ${governance_impact.overall_risk_score.toFixed(1)} / 100 (越高越差)\n`;
            if(governance_impact.conflict_mineral_items && governance_impact.conflict_mineral_items.length > 0){
                summary += `已識別衝突礦產風險: 是\n`;
            }
            if(social_impact.forced_labor_items && social_impact.forced_labor_items.length > 0){
                summary += `已識別強迫勞動風險: 是\n`;
            }
            return summary.trim();
        }

        /**
         * 產生歐盟分類法 - 塑膠製造報告摘要
         */
        function generateEUTaxonomyReportSummary() {
            if (!perUnitData) return "";
            const { impact, virgin_impact, circularity_analysis, regulatory_impact, environmental_performance, biodiversity_impact } = perUnitData;
            let summary = `===== 歐盟分類法對應性評估 - 塑膠製造 =====\n\n`;

            summary += `--- 1. 氣候變遷減緩之實質性貢獻 ---\n`;
            const co2_reduction_pct = virgin_impact.co2 > 0 ? ((virgin_impact.co2 - impact.co2) / virgin_impact.co2 * 100) : 0;
            if (co2_reduction_pct > 20) {
                summary += `[✔ 符合] 產品碳足跡 (${impact.co2.toFixed(2)}) 較原生料基準 (${virgin_impact.co2.toFixed(2)}) 顯著降低 ${co2_reduction_pct.toFixed(1)}%。\n\n`;
            } else {
                summary += `[✘ 需檢視] 產品減碳成效 (${co2_reduction_pct.toFixed(1)}%) 未達顯著水準。\n\n`;
            }

            summary += `--- 2. 轉型至循環經濟之實質性貢獻 ---\n`;
            const recycled_content_pct = circularity_analysis.breakdown.recycled_content_pct;
            const design_for_recycling_score = circularity_analysis.design_for_recycling_score;
            if (recycled_content_pct > 50 && design_for_recycling_score > 70) {
                summary += `[✔ 符合] 產品具備高再生材料佔比 (${recycled_content_pct.toFixed(1)}%) 且易於回收 (潛力分數: ${design_for_recycling_score})。\n\n`;
            } else {
                summary += `[✘ 需檢視] 再生料佔比 (${recycled_content_pct.toFixed(1)}%) 或可回收性設計 (潛力分數: ${design_for_recycling_score}) 有待加強。\n\n`;
            }

            summary += `--- 3. 無重大損害 (Do No Significant Harm, DNSH) 評估 ---\n`;
            summary += `a) 氣候變遷調適: [✔ 符合] 已進行氣候風險評估。\n`;
            const water_score = environmental_performance.breakdown.water;
            summary += `b) 水資源與海洋資源: ${water_score > 60 ? '[✔ 符合]' : '[✘ 需檢視]'} 水資源管理綜合分數為 ${water_score}。\n`;
            const pollution_score = environmental_performance.breakdown.pollution;
            summary += `c) 污染防治: ${pollution_score > 60 && regulatory_impact.svhc_items.length === 0 ? '[✔ 符合]' : '[✘ 需檢視]'} 污染防治分數為 ${pollution_score}，SVHC 項目 ${regulatory_impact.svhc_items.length > 0 ? '已識別' : '未識別'}。\n`;
            const nature_score = environmental_performance.breakdown.nature;
            summary += `d) 生物多樣性與生態系: ${nature_score > 60 && biodiversity_impact.deforestation_risk_items.length === 0 ? '[✔ 符合]' : '[✘ 需檢視]'} 自然資本分數為 ${nature_score}，毀林風險項目 ${biodiversity_impact.deforestation_risk_items.length > 0 ? '已識別' : '未識別'}。\n`;

            return summary.trim();
        }

        // GRI/SASB/EU 報告 Modal 中的按鈕事件
        $(document).on('click', '#generate-esg-report-btn', function() {
            const selectedFramework = $('#frameworkSelector').val();
            let summaryText = '';

            switch(selectedFramework) {
                case 'gri':
                    summaryText = generateGRIReportSummary();
                    break;
                case 'sasb_electronics':
                    summaryText = generateSASBReportSummary();
                    break;
                case 'eu_taxonomy_plastics':
                    summaryText = generateEUTaxonomyReportSummary();
                    break;
                default:
                    summaryText = '錯誤：未知的報告框架。';
            }

            $('#esg-report-output').val(summaryText);
            $('#esg-report-output-container').slideDown();
        });

        $(document).on('click', '#copy-esg-report-btn', function() {
            const output = $('#esg-report-output');
            output.select();
            document.execCommand('copy');
            const btn = $(this);
            const originalHtml = btn.html();
            btn.html('<i class="fas fa-check me-2"></i>已複製!').removeClass('btn-success').addClass('btn-secondary');
            setTimeout(() => { btn.html(originalHtml).removeClass('btn-secondary').addClass('btn-success'); }, 2000);
        });

        const dppModal = new bootstrap.Modal('#dppModal');

        $(document).on('click', '#generate-dpp-btn', function(e) {
            e.preventDefault();
            if (!perUnitData || !perUnitData.view_id) {
                alert('請先「儲存報告」，才能產生可驗證的數位產品護照。');
                return;
            }

            showLoading(true, '正在產生簽章...');

            // 向後端請求此報告的簽章
            $.getJSON(`?action=get_dpp_signature&view_id=${perUnitData.view_id}`)
                .done(function(response) {
                    if (response.success) {
                        const signature = response.signature;
                        const viewId = perUnitData.view_id;

                        // 組合 URL
                        const baseUrl = window.location.href.split('#')[0].split('?')[0].replace('index.php', '');
                        const cardUrl = `${baseUrl}dpp_card.php?view_id=${viewId}&sig=${signature}`;
                        const verifyUrl = `${baseUrl}verify.php?view_id=${viewId}&sig=${signature}`;

                        // 填充 Modal 內容
                        $('#dpp-verify-link').attr('href', verifyUrl);

                        // 產生 iframe 嵌入碼
                        const embedCode = `<iframe src="${cardUrl}" width="370" height="230" frameborder="0" scrolling="no" title="數位產品護照"></iframe>`;
                        $('#dpp-embed-code').val(embedCode);

                        // 產生 QR Code
                        const qrcodeContainer = $('#dpp-qrcode-container');
                        qrcodeContainer.empty();
                        new QRCode(document.getElementById('dpp-qrcode-container'), {
                            text: verifyUrl,
                            width: 150,
                            height: 150,
                        });

                        dppModal.show();
                    } else {
                        alert('產生簽章失敗：' + response.message);
                    }
                })
                .fail(function() {
                    alert('與伺服器通訊失敗，無法取得簽章。');
                })
                .always(function() {
                    showLoading(false);
                });
        });

        // 複製嵌入碼按鈕
        $(document).on('click', '#dpp-copy-embed-code-btn', function() {
            const embedCode = $('#dpp-embed-code');
            embedCode.select();
            document.execCommand('copy');

            const btn = $(this);
            const originalHtml = btn.html();
            btn.html('<i class="fas fa-check me-2"></i>已複製!').removeClass('btn-success').addClass('btn-secondary');
            setTimeout(() => {
                btn.html(originalHtml).removeClass('btn-secondary').addClass('btn-success');
            }, 2000);
        });

        // 當點擊選擇器中的「產生輪播」按鈕時
        $('#generate-comparison-carousel-btn').on('click', function() {
            const selectedIds = [];
            $('#reportChooserModal .form-check-input:checked').each(function() {
                selectedIds.push($(this).val());
            });

            if (selectedIds.length < 2) {
                alert('請至少選擇兩份報告！');
                return;
            }

            const url = `carousel_dashboard.php?action=comparison_carousel&ids[]=${selectedIds.join('&ids[]=')}`;
            window.open(url, '_blank');
            reportChooserModal.hide();
        });

        // 【新增】處理財務風險模擬中的「新增/刪除關稅規則」按鈕功能
        $('#add-tariff-rule-btn').on('click', function() {
            const template = document.getElementById('tariff-rule-template');
            if (!template) {
                console.error('找不到 tariff-rule-template 模板!');
                return;
            }
            const clone = template.content.cloneNode(true);
            const select = clone.querySelector('.tariff-country-select');

            // scenarioBOMCountries 變數是在情境分析視窗打開時生成的
            if (typeof scenarioBOMCountries !== 'undefined' && scenarioBOMCountries.length > 0) {
                scenarioBOMCountries.forEach(country => {
                    const option = document.createElement('option');
                    option.value = country.en;
                    option.textContent = `${country.zh} (${country.en})`;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">BOM中無可用國家</option>';
                select.disabled = true;
            }
            $('#tariff-rules-container').append(clone);
        });

        // 監聽「AI 產生溝通文案」按鈕 (V3.0 - 加入冷卻計時器)
        $(document).on('click', '#generate-comms-content-btn', function() {
            if (!perUnitData) {
                alert('請先進行一次分析。');
                return;
            }

            const btn = $(this);
            const outputContainer = $('#ai-comms-output-container');

            // --- ▼▼▼ 【核心新增】冷卻計時器邏輯 ▼▼▼ ---
            const COOLDOWN_SECONDS = 60; // 設定冷卻時間為 60 秒
            let cooldownInterval;

            const startCooldown = () => {
                btn.prop('disabled', true);
                let secondsLeft = COOLDOWN_SECONDS;

                // 立即更新按鈕文字
                btn.html(`<span class="spinner-border spinner-border-sm me-2"></span>請等待 ${secondsLeft} 秒`);

                cooldownInterval = setInterval(() => {
                    secondsLeft--;
                    if (secondsLeft > 0) {
                        btn.html(`<span class="spinner-border spinner-border-sm me-2"></span>請等待 ${secondsLeft} 秒`);
                    } else {
                        // 計時結束
                        clearInterval(cooldownInterval);
                        btn.prop('disabled', false).html('<i class="fas fa-magic me-2"></i>重新產生溝通文案');
                    }
                }, 1000);
            };
            // --- ▲▲▲ 新增完畢 ▲▲▲ ---

            outputContainer.html(`<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted">AI 正在撰寫中...</p></div>`);

            const storyProfile = generateStoryProfile(perUnitData);
            const payload = {
                reportData: perUnitData,
                storyArchetype: storyProfile
            };

            $.ajax({
                url: '?action=generate_communication_content',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload)
            }).done(function(response) {
                let parsedResponse;
                try {
                    parsedResponse = (typeof response === 'string') ? JSON.parse(response) : response;
                } catch (e) {
                    outputContainer.html(`<div class="alert alert-danger"><strong>前端解析錯誤:</strong><br>伺服器回傳的不是有效的 JSON 格式。<br><hr><strong>伺服器原始回應:</strong><pre>${escapeHtml(response)}</pre></div>`);
                    return;
                }

                if (parsedResponse.success) {
                    const formattedContent = marked.parse(parsedResponse.content);
                    outputContainer.html(formattedContent);
                } else {
                    outputContainer.html(`<div class="alert alert-danger">${parsedResponse.message || 'AI 內容生成失敗。'}</div>`);
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                let errorMsg = `與伺服器通訊時發生錯誤。<br><hr>`;
                errorMsg += `<b>狀態:</b> ${textStatus} (${jqXHR.status})<br>`;
                errorMsg += `<b>錯誤:</b> ${errorThrown}<br>`;
                if (jqXHR.responseText) {
                    errorMsg += `<b>伺服器回應:</b> <pre style="max-height: 150px; overflow-y: auto;">${escapeHtml(jqXHR.responseText)}</pre>`;
                }
                outputContainer.html(`<div class="alert alert-danger">${errorMsg}</div>`);
            }).always(function() {
                // --- ▼▼▼ 【核心新增】無論成功或失敗，都啟動冷卻計時器 ▼▼▼ ---
                startCooldown();
                // --- ▲▲▲ 新增完畢 ▲▲▲ ---
            });
        });

        // --- TNFD 整合：修改/新增 TNFD 相關的 JavaScript 邏輯 ---
        const tnfdFullScreenModal = new bootstrap.Modal('#tnfdFullScreenModal');

        // 模式1：點擊主儀表板的「開啟戰情室」按鈕，進行「即時預覽」
        $(document).on('click', '#open-tnfd-fullscreen-btn', function(e) {
            e.preventDefault();

            if (!perUnitData) {
                alert('請先產生一次分析結果，才能開啟戰情室。');
                return;
            }

            // 核心功能：在開啟前，將當前數據和座標存入 sessionStorage
            sessionStorage.setItem('tempTnfdData', JSON.stringify(perUnitData));
            // COUNTRY_COORDINATES 是您 JS 中已經定義好的全域變數
            sessionStorage.setItem('countryCoordinates', JSON.stringify(COUNTRY_COORDINATES));

            // 設定 iframe 的來源為預覽模式，並開啟 Modal
            const previewUrl = `index.php?action=show_tnfd_war_room&mode=preview&t=${new Date().getTime()}`;
            $('#tnfd-iframe').attr('src', previewUrl);
            tnfdFullScreenModal.show();
        });

        // 模式2：點擊「分析歷程」中的 TNFD 按鈕，開啟「已儲存」的報告
        $('#historyModal').on('click', '.open-tnfd-btn', function() {
            const viewId = $(this).data('view-id');
            if (!viewId) {
                alert('無法取得報告ID。');
                return;
            }

            // 清除 sessionStorage 中的預覽數據，避免衝突
            sessionStorage.removeItem('tempTnfdData');
            // 將座標數據傳過去，因為已儲存的報告也需要它
            sessionStorage.setItem('countryCoordinates', JSON.stringify(COUNTRY_COORDINATES));

            // 直接組合包含 view_id 的 URL
            const secureUrl = `index.php?action=show_tnfd_war_room&view_id=${viewId}`;
            $('#tnfd-iframe').attr('src', secureUrl);
            tnfdFullScreenModal.show();
        });

        // 當 Modal 關閉時，清空 iframe 的 src，釋放記憶體
        $('#tnfdFullScreenModal').on('hidden.bs.modal', function () {
            $('#tnfd-iframe').attr('src', 'about:blank');
        });
        // --- TNFD 整合結束 ---

        // 使用事件委派來處理動態新增的刪除按鈕
        $(document).on('click', '.remove-tariff-rule-btn', function() {
            $(this).closest('.tariff-rule').remove();
        });

        $(document).on('mouseleave', '.legend-item', function() {
            // 滑鼠移開時，移除所有標記的特殊效果
            $('.pie-marker-icon').removeClass('marker-fade marker-highlight');
        });

        // 【無障礙網頁修正】處理彈出視窗關閉後的焦點問題
        $('.modal').on('hidden.bs.modal', function () {
            if (document.activeElement && $(this).has(document.activeElement).length) {
                document.activeElement.blur();
            }
        });

        const usePhaseGridSelector = $('#usePhaseGrid');
        usePhaseGridSelector.empty(); // 清空任何既有選項
        for (const key in GRID_FACTORS) {
            usePhaseGridSelector.append(
                `<option value="${key}">${escapeHtml(GRID_FACTORS[key].name)}</option>`
            );
        }

        // --- 全新 V5.0：使用階段模式切換 (簡易/專家 + 參數微調) 完整邏輯 ---
        const scenarioSelector = $('#usePhaseScenarioSelector');
        const usageSlider = $('#usageFrequencySlider');
        const simpleModeWrapper = $('#simple-mode-wrapper');
        const expertModeWrapper = $('#expert-mode-wrapper');
        const detailsContainer = $('#simple-mode-details');

        // 1. 【錯誤修正】初始化簡易模式的下拉選單 (支援分類)
        scenarioSelector.empty();
        for (const groupName in USE_PHASE_SCENARIOS) {
            const groupData = USE_PHASE_SCENARIOS[groupName];
            // 簡易模式中過濾掉 "手動模式" 這個分類
            if (groupName === '手動模式') continue;

            const groupOpt = $(`<optgroup label="${escapeHtml(groupName)}"></optgroup>`);
            groupData.forEach(scenario => {
                groupOpt.append(`<option value="${scenario.key}">${escapeHtml(scenario.name)}</option>`);
            });
            scenarioSelector.append(groupOpt);
        }

        // 2. 【新增】渲染「參數微調」面板的函式
        function renderSimpleModeDetails(scenario, intensityFactor) {
            if (scenario.type === 'manual' || !scenario.base) {
                detailsContainer.html('');
                return;
            }

            let detailsHtml = '<div class="row g-2">';
            const createInputHtml = (label, key, unit, currentValue) => {
                return `<div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">${label}</span><input type="number" class="form-control detail-input" data-key="${key}" value="${currentValue.toFixed(2)}"><span class="input-group-text">${unit}</span></div></div>`;
            };

            if (scenario.type === 'continuous') {
                const hours_adj = (scenario.multiplier.hours_per_day || 0) * intensityFactor;
                const final_hours = scenario.base.hours_per_day + hours_adj;
                detailsHtml += createInputHtml('功率', 'power_w', 'W', scenario.base.power_w);
                detailsHtml += createInputHtml('每日時數', 'hours_per_day', 'hr', final_hours);
                detailsHtml += createInputHtml('每週天數', 'days_per_week', 'day', scenario.base.days_per_week);
            } else if (scenario.type === 'cyclic') {
                const cycles_adj = (scenario.multiplier.cycles_per_week || 0) * intensityFactor;
                const final_cycles = scenario.base.cycles_per_week + cycles_adj;
                detailsHtml += createInputHtml('單次耗電', 'kwh_per_cycle', 'kWh', scenario.base.kwh_per_cycle);
                detailsHtml += createInputHtml('單次耗水', 'water_per_cycle', 'L', scenario.base.water_per_cycle);
                detailsHtml += createInputHtml('每週次數', 'cycles_per_week', '次', final_cycles);
            } else if (scenario.type === 'annual') {
                const kwh_adj = (scenario.multiplier.kwh_per_year || 0) * intensityFactor;
                const final_kwh = scenario.base.kwh_per_year + kwh_adj;
                detailsHtml += createInputHtml('年耗電', 'kwh_per_year', 'kWh', final_kwh);
            }
            detailsHtml += '</div>';
            detailsContainer.html(detailsHtml);
        }

        // 3. 核心計算函式：根據簡易模式或微調後的參數，計算最終年度值
        function calculateAndUpdate() {
            const scenarioKey = scenarioSelector.val();
            const scenario = Object.values(USE_PHASE_SCENARIOS).flat().find(s => s.key === scenarioKey);
            if (!scenario) return;

            const sliderValue = parseInt(usageSlider.val());
            const intensityFactor = (sliderValue / 50) - 1;

            $('#usePhaseLifespan').val(scenario.lifespan);

            let annualKwh = 0, annualWater = 0;
            const detailInputs = {};
            detailsContainer.find('.detail-input').each(function() {
                detailInputs[$(this).data('key')] = parseFloat($(this).val()) || 0;
            });

            // 優先使用微調後的值，如果微調面板是空的，則使用滑桿計算
            if (Object.keys(detailInputs).length > 0) {
                if (scenario.type === 'continuous') {
                    annualKwh = (detailInputs.power_w * detailInputs.hours_per_day * detailInputs.days_per_week * 52) / 1000;
                } else if (scenario.type === 'cyclic') {
                    annualKwh = detailInputs.kwh_per_cycle * detailInputs.cycles_per_week * 52;
                    annualWater = detailInputs.water_per_cycle * detailInputs.cycles_per_week * 52;
                } else if (scenario.type === 'annual') {
                    annualKwh = detailInputs.kwh_per_year;
                }
            } else {
                // 滑桿計算邏輯 (與 V4 相同)
                if (scenario.type === 'continuous') {
                    const hours_adj = (scenario.multiplier.hours_per_day || 0) * intensityFactor;
                    const final_hours = scenario.base.hours_per_day + hours_adj;
                    annualKwh = (scenario.base.power_w * final_hours * scenario.base.days_per_week * 52) / 1000;
                } else if (scenario.type === 'cyclic') {
                    const cycles_adj = (scenario.multiplier.cycles_per_week || 0) * intensityFactor;
                    const final_cycles = scenario.base.cycles_per_week + cycles_adj;
                    annualKwh = scenario.base.kwh_per_cycle * final_cycles * 52;
                    annualWater = scenario.base.water_per_cycle * final_cycles * 52;
                } else if (scenario.type === 'annual') {
                    const kwh_adj = (scenario.multiplier.kwh_per_year || 0) * intensityFactor;
                    annualKwh = scenario.base.kwh_per_year + kwh_adj;
                }
                renderSimpleModeDetails(scenario, intensityFactor);
            }

            $('#usePhaseKwh').val(annualKwh > 0 ? annualKwh.toFixed(2) : '0');
            $('#usePhaseWater').val(annualWater > 0 ? annualWater.toFixed(2) : '0');
        }

        // 4. 監聽所有相關的 UI 變動事件
        $('input[name="usePhaseMode"]').on('change', function() {
            if ($(this).val() === 'simple') {
                simpleModeWrapper.show();
                expertModeWrapper.hide();

                // 【核心修正】啟用簡易模式的輸入，禁用專家模式的輸入
                simpleModeWrapper.find('select, input').prop('disabled', false);
                expertModeWrapper.find('input').prop('disabled', true);

                calculateAndUpdate();
            } else {
                simpleModeWrapper.hide();
                expertModeWrapper.show();

                // 【核心修正】禁用簡易模式的輸入，啟用專家模式的輸入
                simpleModeWrapper.find('select, input').prop('disabled', true);
                expertModeWrapper.find('input').prop('disabled', false);

                const expertScenario = USE_PHASE_SCENARIOS['手動模式'][0];
                if(expertScenario) $('#usePhaseLifespan').val(expertScenario.lifespan);
            }
            triggerCalculation();
        });

        scenarioSelector.on('change', function() {
            usageSlider.val(50); // 重置滑桿
            detailsContainer.empty().hide(); // 選擇新項目時先清空舊的微調面板
            $('#toggle-simple-details i').removeClass('fa-chevron-up').addClass('fa-chevron-down');

            const selectedKey = $(this).val();
            const scenario = Object.values(USE_PHASE_SCENARIOS).flat().find(s => s.key === selectedKey);
            const warningDiv = $('#implicit-bom-warning');

            if (scenario && scenario.implicit_bom && scenario.implicit_bom.length > 0) {
                const warningText = scenario.implicit_bom[0].description;
                warningDiv.html(`<strong>注意：</strong> ${escapeHtml(warningText)}`).slideDown();
            } else {
                warningDiv.slideUp();
            }

            calculateAndUpdate();
            triggerCalculation();
        });

        /**
         * 【V12.11 - Bug 修正版】監聽使用頻率滑桿
         * - 確保 calculateAndUpdate 和 triggerCalculation 被正確呼叫
         */
        usageSlider.on('input', function() {
            // 1. 立即根據滑桿值，更新簡易模式中的詳細參數
            calculateAndUpdate();

            // 2. 使用 debounce 機制，在使用者停止滑動後，觸發一次完整的後端計算
            debounce(() => {
                saveState();
                triggerCalculation();
            }, 400)();
        });

        detailsContainer.on('input', '.detail-input', function() {
            calculateAndUpdate();
            debounce(triggerCalculation, 300)();
        });

        $('#toggle-simple-details').on('click', function(e){
            e.preventDefault();
            const icon = $(this).find('i');
            if(detailsContainer.is(':visible')){
                detailsContainer.slideUp();
                icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                // 第一次打開時，先渲染
                if(detailsContainer.is(':empty')){
                    calculateAndUpdate();
                }
                detailsContainer.slideDown();
                icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
        });

        // 5. 初始化
        calculateAndUpdate();

        // --- 初始化 ---
        const savedTheme = localStorage.getItem('selectedTheme') || 'light-green';
        $('#theme-selector').val(savedTheme);
        applyTheme(savedTheme);
        loadAndApplySettings(); // 設定檔可以先載入

        // 【V10.0 核心修改】啟動模式選擇邏輯
        const startupModal = new bootstrap.Modal('#startupModal');
        const historyModalForStartup = new bootstrap.Modal('#historyModal');

        // 檢查是否存在上次的暫存資料
        if (localStorage.getItem('ecoCalculatorState')) {
            $('#load-last-session-btn').prop('disabled', false);
        }

        // 顯示選擇視窗
        startupModal.show();

        // 綁定按鈕事件
        $('#start-new-analysis-btn').on('click', function() {
            startupModal.hide();
            // 執行全新的分析流程
            $('#materials-list-container').empty();
            addMaterialRow();
            updateTotalWeight();
            validateEol();
        });

        $('#load-last-session-btn').on('click', function() {
            startupModal.hide();
            // 執行載入上次狀態的流程
            loadState();
        });

        $('#load-from-history-btn').on('click', function() {
            startupModal.hide();
            // 打開歷史紀錄視窗
            $('#open-history-btn').trigger('click');
        });

        function generateSdgIconsHtml(sdg_numbers) {
            if (!Array.isArray(sdg_numbers) || sdg_numbers.length === 0) {
                return '';
            }

            const sdg_titles = {
                1: "SDG 1: 終結貧窮", 2: "SDG 2: 消除飢餓", 3: "SDG 3: 健康與福祉",
                4: "SDG 4: 優質教育", 5: "SDG 5: 性別平等", 6: "SDG 6: 潔淨水與衛生",
                7: "SDG 7: 可負擔的潔淨能源", 8: "SDG 8: 尊嚴就業與經濟發展", 9: "SDG 9: 產業、創新與基礎設施",
                10: "SDG 10: 減少不平等", 11: "SDG 11: 永續城市與社區", 12: "SDG 12: 責任消費與生產",
                13: "SDG 13: 氣候行動", 14: "SDG 14: 海洋生態", 15: "SDG 15: 陸域生態",
                16: "SDG 16: 和平、正義與健全制度", 17: "SDG 17: 夥伴關係"
            };

            let html = '<div class="sdg-icons-container ms-auto d-flex align-items-center">';
            sdg_numbers.forEach(num => {
                const formatted_num = String(num).padStart(2, '0');
                const title = sdg_titles[num] || `SDG ${num}`;
                html += `<img src='assets/img/SDGs_${formatted_num}.png' alt='SDG ${num}' class='sdg-icon' data-bs-toggle='tooltip' data-bs-placement='top' title='${title}'>`;
            });
            html += '</div>';
            return html;
        }
        // --- 延伸應用中心 Modal ---
        const extendedAppsModal = document.getElementById('extendedAppsModal');
        if (extendedAppsModal) {
            extendedAppsModal.addEventListener('show.bs.modal', event => {
                const iframe = document.getElementById('extended-apps-iframe');
                // 透過在顯示時才設定 src，確保內容永遠是新的
                if (iframe.src === 'about:blank') {
                    // 加上時間戳記防止快取問題
                    iframe.src = 'extended_apps.php?t=' + new Date().getTime();
                }
            });
        }

        /**
         * 【V12.5 - 即時計算修正版】統一監聽所有製程相關的輸入變動
         * @description 整合了數量、動態選項、作用對象的監聽，並使用 'input' 事件提升即時性。
         */
        $('#materials-list-container').on('input change', '.process-quantity, .process-option-select, .applied-to-selector', function() {
            // 確保在有一次成功分析結果後才觸發即時計算
            if (perUnitData) {
                // 使用 debounce 防止過於頻繁的觸發，特別是對於快速輸入
                debounce(() => {
                    saveState();
                    triggerCalculation();
                }, 400)();
            } else {
                // 如果還沒有分析結果，只儲存狀態即可
                saveState();
            }
        });

        // ⭐ START: 全新升級版的 AI 視覺辨識功能 JavaScript (V3.2 - 移除 AI 建立流程)
        const aiSuggestionModal = new bootstrap.Modal('#aiSuggestionModal');
        // const aiComponentEditorModal = new bootstrap.Modal('#ai-component-editor-modal'); // <-- 已移除

        $('#ai-vision-btn').on('click', function() {
            if (!confirm('您確定要使用 AI 視覺辨識嗎？這將會清除您目前編輯的物料清單。')) return;
            $('#ai-vision-input').click();
        });

        $('#ai-vision-input').on('change', function(event) {
            const file = event.target.files[0]; if (!file) return;
            showLoading(true, 'AI 辨識中...');
            const reader = new FileReader();
            reader.onload = function(e) {
                $.ajax({
                    url: '?action=identify_from_image', type: 'POST', contentType: 'application/json',
                    data: JSON.stringify({ image_data: e.target.result }), dataType: 'json',
                    success: (res) => { if (res.success && res.data) populateAiSuggestionModal(res.data); else Swal.fire('辨識失敗', res.message, 'error'); },
                    error: () => Swal.fire('伺服器錯誤', '與後端AI服務的通訊失敗。', 'error'),
                    complete: () => { showLoading(false); $('#ai-vision-input').val(''); }
                });
            };
            reader.readAsDataURL(file);
        });

        function populateAiSuggestionModal(aiData) {
            $('#ai-object-name').text(aiData.objectName || '未知物件');
            $('#ai-materials-suggestions').empty().append(aiData.materials.map((m, i) => createSuggestionRow('material', i, m, findMatches(m.name, ALL_MATERIALS, 'name', 'key'))));
            $('#ai-processes-suggestions').empty().append(aiData.processes.map((p, i) => createSuggestionRow('process', i, p, findMatches(p.name, ALL_PROCESSES, 'name', 'process_key'))));
            aiSuggestionModal.show();
        }

        function findMatches(name, source, nameField) { return source.filter(i => i[nameField].toLowerCase().includes(name.toLowerCase())); }

        /**
         * 【V3.2 - 修改版】產生 AI 建議的物料/製程列
         * @description 當找不到匹配項時，提供完整的選項列表並連結至管理後台，而非彈出建立視窗。
         */
        function createSuggestionRow(type, index, item, matches) {
            const idPrefix = `${type}-${index}`;
            const isMaterial = type === 'material';

            // 決定要使用的資料來源：
            // 1. 如果有 AI 匹配的結果 (matches.length > 0)，就使用匹配結果。
            // 2. 如果沒有匹配結果，則根據類型使用完整的物料庫 (ALL_MATERIALS) 或製程庫 (ALL_PROCESSES)。
            const dataSource = (matches.length > 0) ? matches : (isMaterial ? ALL_MATERIALS : ALL_PROCESSES);
            const keyField = isMaterial ? 'key' : 'process_key';

            // 產生下拉選單的 HTML
            let optionsHtml = `<select class="form-select form-select-sm suggestion-select" id="${idPrefix}-select">`;
            if (matches.length === 0) {
                // 當沒有匹配項時，給一個提示性的預設選項
                optionsHtml += `<option value="" disabled selected>AI 無法自動匹配，請手動選擇...</option>`;
            }
            // 根據 dataSource 填入所有選項
            optionsHtml += dataSource.map(m => {
                const name = m.name || m.process_name; // 兼容不同命名
                return `<option value="${m[keyField]}">${escapeHtml(name)}</option>`;
            }).join('');
            optionsHtml += `</select>`;

            // 只有在找不到匹配項時，才顯示「管理資料庫」的按鈕
            let managementButtonHtml = '';
            if (matches.length === 0) {
                // 連結至 manage_materials.php，並在新分頁開啟
                managementButtonHtml = `<a href="manage_materials.php" target="_blank" class="btn btn-outline-success btn-sm" role="button" title="在新分頁開啟資料庫管理">管理資料庫</a>`;
            }

            // 將所有 UI 元件組合起來
            const finalOptionsHtml = `<div class="input-group input-group-sm">
            ${optionsHtml}
            ${managementButtonHtml}
            <button class="btn btn-outline-danger btn-sm remove-ai-suggestion-btn" type="button" title="移除此建議"><i class="fas fa-trash"></i></button>
        </div>`;

            return `<div class="card mb-2 suggestion-card" id="${idPrefix}-card" data-original-name="${escapeHtml(item.name)}" data-estimated-pct="${item.estimated_weight_pct || ''}">
            <div class="card-body p-2">
                <p class="mb-1 small"><strong>AI 建議:</strong> ${escapeHtml(item.name)} ${isMaterial ? `<span class="badge bg-info float-end">預估 ${item.estimated_weight_pct}% 重量</span>` : ''}</p>
                ${finalOptionsHtml}
            </div>
        </div>`;
        }

        // 【V3.1 升級版】點擊「建立新項目」按鈕 -> 已被移除

        // 【V3.1 升級版】處理 AI 輔助編輯器 Modal 的儲存按鈕 -> 已被移除

        $('#confirm-ai-bom-btn').on('click', function() {
            const finalBOM = { materials: [], processes: [] };
            let hasError = false;

            $('.suggestion-card').each(function() {
                const card = $(this);
                const isMaterial = card.attr('id').startsWith('material');
                let finalKey = card.data('final-key') || card.find('.suggestion-select').val();

                if (!finalKey) {
                    hasError = true;
                    card.addClass('border-danger');
                    return;
                }
                card.removeClass('border-danger');

                if (isMaterial) {
                    finalBOM.materials.push({ key: finalKey, estimated_pct: card.data('estimated-pct') });
                } else {
                    // 確保製程不重複
                    if (!finalBOM.processes.some(p => p.key === finalKey)) {
                        finalBOM.processes.push({ key: finalKey });
                    }
                }
            });

            if (hasError) { Swal.fire('請完成所有選擇', '部分項目尚未匹配或建立。', 'warning'); return; }

            $('#materials-list-container').empty();
            const totalWeightGrams = parseFloat(prompt("AI 已辨識完成！請輸入此產品的大約總重量（公克）:", "100")) || 100;

            const materialKeysInBom = [];
            finalBOM.materials.forEach(mat => {
                const weightInKg = totalWeightGrams * (parseFloat(mat.estimated_pct || 0) / 100) / 1000;
                addMaterialRow({ materialKey: mat.key, weight: weightInKg.toFixed(4) });
                materialKeysInBom.push(mat.key);
            });

            finalBOM.processes.forEach(proc => {
                addProcessRow({ processKey: proc.key, quantity: 1, appliedToComponentKey: materialKeysInBom });
            });

            aiSuggestionModal.hide();
            updateTotalWeight();
            saveState();
            triggerCalculation();
            Swal.fire('成功!', 'AI 已自動為您填入BOM資料！', 'success');
        });

        // 【V3.1 AI視覺辨識功能升級】新增移除 AI 建議的事件處理
        $(document).on('click', '.remove-ai-suggestion-btn', function() {
            const card = $(this).closest('.suggestion-card');
            const itemName = card.data('original-name');

            // 使用 SweetAlert2 進行安全確認
            Swal.fire({
                title: `確定要移除「${escapeHtml(itemName)}」嗎？`,
                text: "此 AI 建議將會從列表中刪除。",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '是的，移除',
                cancelButtonText: '取消'
            }).then((result) => {
                if (result.isConfirmed) {
                    // 確認後，以淡出效果移除卡片
                    card.fadeOut(400, function() {
                        $(this).remove();
                    });
                }
            });
        });
// ⭐ END: AI 視覺辨識功能

    });
</script>
</body>
</html>