export type InsightRankingItem = {
    name: string;
    code?: string;
    unit?: string;
    salesSen: number;
    unitsSold?: number;
    patients: number;
};

export type InsightTimeMetric = {
    average: number;
    longest: number;
    longestAt: string | null;
    previousAverage: number;
    previousLongest: number;
    trend: { hour: number; minutes: number }[];
};

export type TodayInsightsReport = {
    date: string;
    generatedAt: string;
    sales: {
        totalSen: number;
        newSen: number;
        returningSen: number;
        patientCount: number;
        averagePerPatientSen: number;
        previousTotalSen: number;
        previousPatientCount: number;
        previousAveragePerPatientSen: number;
    };
    salesTrend: { hour: number; salesSen: number }[];
    rankings: {
        services: InsightRankingItem[];
        medicines: InsightRankingItem[];
        packages: InsightRankingItem[];
        providers: InsightRankingItem[];
    };
    time: {
        inClinic: InsightTimeMetric;
        waiting: InsightTimeMetric;
        serving: InsightTimeMetric;
    };
};

export type RangeInsightRow = {
    name: string;
    salesSen?: number;
    amountSen?: number;
    unitsSold?: number;
    patients?: number;
    billedSen?: number;
    approvedSen?: number;
    rejectedSen?: number;
};

export type InsightsRangeReport =
    | {
          kind: 'sales';
          summary: {
              totalSen: number;
              newSen: number;
              returningSen: number;
              patients: number;
              perPatientSen: number;
              previousTotalSen: number;
              previousPatients: number;
              previousPerPatientSen: number;
          };
          dailyTrend: { date: string; salesSen: number }[];
          services: RangeInsightRow[];
          medicines: RangeInsightRow[];
          packages: RangeInsightRow[];
          providers: RangeInsightRow[];
          rankingTruncated: {
              services: boolean;
              medicines: boolean;
              providers: boolean;
          };
      }
    | {
          kind: 'in-clinic';
          time: Record<
              'inClinic' | 'waiting' | 'serving',
              {
                  average: number;
                  longest: number;
                  longestAt: string | null;
                  trend: { date: string; minutes: number }[];
                  previousAverage: number;
                  previousLongest: number;
                  previousLongestAt: string | null;
                  previousTrend: { date: string; minutes: number }[];
              }
          >;
          dailyVisits: { date: string; patients: number }[];
          hourlyPatients: { hour: number; patients: number }[];
          priority: { urgent: number; normal: number };
          doctorOccupancy: Record<number, Record<number, number>>;
      }
    | {
          kind: 'payments';
          summary: {
              selfPaySalesSen: number;
              panelSalesSen: number;
              receivedSen: number;
              outstandingSen: number;
              outstandingInvoices: number;
          };
          paymentMethods: RangeInsightRow[];
          panels: RangeInsightRow[];
      }
    | {
          kind: 'inventory';
          summary: {
              lowStockItems: number;
              expiringBatchesNext30Days: number;
              wastageUnits: number;
              inventoryValueAvailable: boolean;
              costOfInventorySoldAvailable: boolean;
          };
          medicineSales: RangeInsightRow[];
      }
    | {
          kind: 'patients';
          summary: {
              patients: number;
              visits: number;
              appointmentsAvailable: boolean;
          };
          age: Record<string, number>;
          gender: Record<string, number>;
          visitFrequency: Record<string, number>;
          walkInVsAppointment: null;
      };

export type InsightsRangeFilters = {
    section: InsightsRangeReport['kind'];
    branch: string;
    from: string;
    to: string;
    doctor: number | null;
};
