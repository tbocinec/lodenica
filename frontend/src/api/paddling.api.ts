import { http } from './http';

/** Shape relayed by our backend proxy from dunajcik.sk. */
export interface PaddlingTrafficLight {
  location: { name: string; river: string; water_station_id?: string };
  weather: {
    condition: string;
    description: string;
    icon: string;
    temperature: { celsius: number };
    wind: { speed_mps: number };
    pressure_hpa: number;
    humidity_percent: number;
    observed_at: string;
  };
  danube: {
    water_level: { value: number; unit: string };
    water_temperature: { value: number; unit: string };
    measured_at: string;
    source: string;
  };
  daylight: { sunrise: string; sunset: string; is_night: boolean };
  recommendation: {
    level: 'green' | 'orange' | 'red';
    label: string;
    message: string;
    color: { name: string; hex: string };
    reasons: string[];
    thresholds: {
      orange: { water_level_cm: number; wind_mps: number; weather: string[] };
      red: { water_level_cm: number; wind_mps: number; weather: string[] };
    };
  };
}

/** A single Danube gauge reading (used for the Devín card, sourced from SHMÚ). */
export interface DanubeReading {
  water_level: { value: number; unit: string };
  water_temperature: { value: number; unit: string } | null;
  measured_at: string;
  source: string;
}

export interface PaddlingTrafficLightResponse {
  data: PaddlingTrafficLight;
  /** Devín gauge (SHMÚ), or null when unavailable. dunajcik covers Bratislava. */
  devin?: DanubeReading | null;
  source: string;
  sourceUrl: string;
}

export const paddlingApi = {
  async get(): Promise<PaddlingTrafficLightResponse> {
    const { data } = await http.get<PaddlingTrafficLightResponse>('/paddling-traffic-light');
    return data;
  },
};
