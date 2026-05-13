import {
  ArgumentsHost,
  Catch,
  ExceptionFilter,
  HttpException,
  HttpStatus,
} from '@nestjs/common';
import { Prisma } from '@prisma/client';
import { Request, Response } from 'express';
import { Logger } from 'nestjs-pino';

import { DomainError } from '../errors/domain.errors';

interface ErrorBody {
  statusCode: number;
  error: string;
  code?: string;
  message: string | string[];
  details?: unknown;
  path: string;
  timestamp: string;
}

@Catch()
export class GlobalExceptionFilter implements ExceptionFilter {
  constructor(private readonly logger: Logger) {}

  catch(exception: unknown, host: ArgumentsHost): void {
    const ctx = host.switchToHttp();
    const response = ctx.getResponse<Response>();
    const request = ctx.getRequest<Request>();

    const body = this.toBody(exception, request.url);

    if (body.statusCode >= 500) {
      this.logger.error(
        { err: exception, path: request.url, method: request.method },
        'Unhandled error',
      );
    } else {
      this.logger.debug(
        { err: exception, path: request.url, method: request.method, code: body.code },
        'Handled error',
      );
    }

    response.status(body.statusCode).json(body);
  }

  private toBody(exception: unknown, path: string): ErrorBody {
    const timestamp = new Date().toISOString();

    if (exception instanceof DomainError) {
      const status = this.statusForDomainCode(exception.code);
      return {
        statusCode: status,
        error: HttpStatus[status] ?? 'Error',
        code: exception.code,
        message: exception.message,
        details: exception.details,
        path,
        timestamp,
      };
    }

    if (exception instanceof HttpException) {
      const status = exception.getStatus();
      const res = exception.getResponse();
      const isObj = typeof res === 'object' && res !== null;
      const message = isObj ? ((res as { message?: string | string[] }).message ?? exception.message) : (res as string);
      return {
        statusCode: status,
        error: HttpStatus[status] ?? 'Error',
        message,
        details: isObj ? (res as Record<string, unknown>) : undefined,
        path,
        timestamp,
      };
    }

    if (exception instanceof Prisma.PrismaClientKnownRequestError) {
      return this.handlePrismaError(exception, path, timestamp);
    }

    return {
      statusCode: HttpStatus.INTERNAL_SERVER_ERROR,
      error: 'Internal Server Error',
      message: 'Neočakávaná chyba servera.',
      path,
      timestamp,
    };
  }

  private statusForDomainCode(code: DomainError['code']): number {
    switch (code) {
      case 'RESOURCE_NOT_FOUND':
        return HttpStatus.NOT_FOUND;
      case 'RESOURCE_CONFLICT':
      case 'RESERVATION_OVERLAP':
        return HttpStatus.CONFLICT;
      case 'RESERVATION_INVALID_RANGE':
      case 'RESERVATION_RESOURCE_INACTIVE':
      case 'VALIDATION_ERROR':
        return HttpStatus.BAD_REQUEST;
      default:
        return HttpStatus.BAD_REQUEST;
    }
  }

  private handlePrismaError(
    err: Prisma.PrismaClientKnownRequestError,
    path: string,
    timestamp: string,
  ): ErrorBody {
    // P2002 unique constraint, P2025 record not found, 23P01 exclusion violation
    if (err.code === 'P2002') {
      return {
        statusCode: HttpStatus.CONFLICT,
        error: 'Conflict',
        code: 'UNIQUE_CONSTRAINT',
        message: 'Záznam s týmito hodnotami už existuje.',
        details: { target: err.meta?.target },
        path,
        timestamp,
      };
    }
    if (err.code === 'P2025') {
      return {
        statusCode: HttpStatus.NOT_FOUND,
        error: 'Not Found',
        code: 'RESOURCE_NOT_FOUND',
        message: 'Záznam nebol nájdený.',
        path,
        timestamp,
      };
    }
    // Exclusion constraint violation surfaces from Postgres (23P01).
    const message = (err.message || '').toLowerCase();
    if (message.includes('reservations_no_overlap_excl') || message.includes('exclusion')) {
      return {
        statusCode: HttpStatus.CONFLICT,
        error: 'Conflict',
        code: 'RESERVATION_OVERLAP',
        message: 'Vybraný zdroj je v zadanom termíne už rezervovaný.',
        path,
        timestamp,
      };
    }
    return {
      statusCode: HttpStatus.BAD_REQUEST,
      error: 'Bad Request',
      code: err.code,
      message: 'Chyba databázy.',
      path,
      timestamp,
    };
  }
}
