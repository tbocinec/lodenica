import { http } from './http';
import type { AuditAction, AuditEntityType, AuditLog, Paginated } from './types';

export interface ListAuditLogsParams {
  entityType?: AuditEntityType;
  entityId?: string;
  action?: AuditAction;
  /** ISO datetime — list rows with createdAt >= from */
  from?: string;
  /** ISO datetime — list rows with createdAt < to */
  to?: string;
  page?: number;
  pageSize?: number;
}

export const auditApi = {
  async list(params: ListAuditLogsParams = {}): Promise<Paginated<AuditLog>> {
    const { data } = await http.get<Paginated<AuditLog>>('/audit-logs', { params });
    return data;
  },
};
